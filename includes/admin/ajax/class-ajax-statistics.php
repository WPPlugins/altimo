<?php

defined( 'ABSPATH' ) || exit;

/**
 * Dünner AJAX-Controller für `get_statistics` (QUAL-01).
 *
 * 1:1 aus `includes/class-admin-handler.php:1280-1295` übernommen, keine
 * Verhaltensänderung (D-06).
 */
class Image_AI_SEO_Ajax_Statistics {

	/**
	 * Registriert `wp_ajax_image_ai_seo_get_statistics`.
	 */
	public static function init() {
		add_action( 'wp_ajax_image_ai_seo_get_statistics', array( __CLASS__, 'get_statistics' ) );
	}

	/**
	 * AJAX Handler: Holt die Statistiken
	 */
	public static function get_statistics() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$statistics = Image_AI_SEO_Statistics_Handler::get_all_statistics();

		wp_send_json_success( $statistics );
	}
}
