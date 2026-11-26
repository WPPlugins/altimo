<?php

defined( 'ABSPATH' ) || exit;

/**
 * Dünner AJAX-Controller für `update_image_metadata` (QUAL-01).
 *
 * Kanonischer Aufrufkörper stammt aus `includes/class-admin-handler.php:804-838`
 * (nutzt `sanitize_text_field()` für das Description-Feld) -- das ist die
 * AKTUELL TATSÄCHLICH GEWINNENDE Implementierung, weil `Admin_Handler::init()`
 * bislang zuerst registriert. Die Variante in `ImageAiSeo::update_image_metadata()`
 * (nutzte den mehrzeiligen Sanitizer) hat NIE gewonnen und wird bewusst NICHT
 * übernommen -- das wäre eine unautorisierte Verhaltensänderung (D-06).
 */
class Image_AI_SEO_Ajax_Metadata {

	/**
	 * Registriert `wp_ajax_image_ai_seo_update_image_metadata` GENAU EINMAL.
	 */
	public static function init() {
		add_action( 'wp_ajax_image_ai_seo_update_image_metadata', array( __CLASS__, 'update_image_metadata' ) );
	}

	/**
	 * AJAX Handler: Speichert Titel, Alt-Text, Beschreibung und Dateinamen.
	 */
	public static function update_image_metadata() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to do this.', 'altimo' ),
				)
			);
		}

		$post_id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$alt         = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$filename    = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		Image_AI_SEO_Ajax_Guard::require_attachment_edit( $post_id );

		// Update Metadaten
		Image_AI_SEO_Metadata_Handler::update_image_metadata( $post_id, $title, $alt, $description );

		// Update Dateinamen
		$old_file      = get_attached_file( $post_id );
		$rename_result = Image_AI_SEO_File_Handler::rename_attachment( $post_id, $filename, $old_file );
		$rename_notice = '';

		if ( is_wp_error( $rename_result ) ) {
			// RENAME-03/T-04-15: Die Metadaten hat der Nutzer selbst eingegeben und
			// sie sind bereits gespeichert -- sie werden hier NICHT zurückgerollt.
			// Verweigerte oder fehlgeschlagene Umbenennungen dürfen aber nicht
			// stillschweigend verschwinden (bisher wurde der Rückgabewert gar nicht
			// geprüft), also wird der Grund immer mitgeliefert.
			$rename_notice = $rename_result->get_error_message();

			if ( 'offload_detected' !== $rename_result->get_error_code() ) {
				Image_AI_SEO_Plugin::log(
					sprintf(
						'Altimo: Failed to rename file during update_image_metadata for attachment %d (%s): %s',
						$post_id,
						$rename_result->get_error_code(),
						$rename_result->get_error_message()
					)
				);
			}
		}

		// Phase-3-Vertrag: der TATSÄCHLICH angewendete Dateiname, nicht der
		// gewünschte -- bei Verweigerung also der unveränderte alte.
		$actual_filename = pathinfo( basename( get_attached_file( $post_id ) ), PATHINFO_FILENAME );

		// Hole die aktualisierten Statistiken
		$total_optimized = Image_AI_SEO_Metadata_Handler::get_total_optimized_images();

		wp_send_json_success(
			array(
				'message'         => __( 'Changes saved successfully.', 'altimo' ),
				'new_filename'    => $actual_filename,
				'total_optimized' => $total_optimized,
				'notice'          => $rename_notice,
			)
		);
	}
}
