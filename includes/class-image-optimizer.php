<?php

defined( 'ABSPATH' ) || exit;

class Image_AI_SEO_Image_Optimizer {

	/**
	 * Ruft die KI-Optimierungs-API auf und liefert einen Optimierungsvorschlag zurück.
	 *
	 * Reiner API-Call: validiert Eingaben, ruft Image_AI_SEO_Api_Client::post() auf und
	 * normalisiert/sanitized die Antwort. Schreibt NICHTS an Metadaten, Dateien oder
	 * History -- die einzige Ausnahme ist die Credits-Options-Buchführung, die Teil der
	 * API-Antwort-Verarbeitung ist, kein Bild-Schreibvorgang (QUAL-04).
	 *
	 * D-14: Der zurückgegebene `filename` ist immer endungslos (pathinfo(...,
	 * PATHINFO_FILENAME) nach sanitize_file_name()), unabhängig davon, ob die API bereits
	 * eine Endung mitliefert. Das verhindert doppelte Endungen an jeder Aufrufstelle, die
	 * diesen Wert weiterverarbeitet (Image_AI_SEO_File_Handler::rename_attachment() hängt
	 * die Endung selbst an; bulk_optimize_image() hängt sie ebenfalls selbst an).
	 *
	 * @param int $image_id Die ID des zu optimierenden Bildes.
	 * @return array Response mit Status und optimierten Vorschlagsdaten (data.title,
	 *               data.alt_text, data.description, data.filename, data.credits).
	 */
	public static function generate( $image_id ) {
		// Prüfe, ob es sich um ein Bild handelt
		if ( ! wp_attachment_is_image( $image_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'The selected file is not an image.', 'altimo' ),
			);
		}

		// Hole den API-Key (D-01: Seiten-Schlüssel überschreibt Netzwerk-Schlüssel,
		// einzig aufgelöst in Image_AI_SEO_Api_Client::get_active_api_key()).
		$api_key = Image_AI_SEO_Api_Client::get_active_api_key()['key'];
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'No API key found. Please add an API key in the settings.', 'altimo' ),
			);
		}

		// Hole die Bild-URL
		$image_url = wp_get_attachment_url( $image_id );
		if ( ! $image_url ) {
			return array(
				'success' => false,
				'message' => __( 'Image URL could not be determined.', 'altimo' ),
			);
		}

		// Hole die gespeicherten Einstellungen. Das Fokus-Keyword wird per Post
		// aus dem aktiven SEO-Plugin aufgelöst (SEO-01/D-11); ohne Treffer greift
		// das manuelle Keyword wie bisher (D-12, nie schlechter als heute).
		$focus_keyword       = Image_AI_SEO_Seo_Integration::resolve_focus_keyword( $image_id );
		$additional_keywords = get_option( 'image_ai_seo_additional_keywords', '' );
		$website_description = get_option( 'image_ai_seo_website_description', '' );
		$language            = get_option( 'image_ai_seo_language', 'en' );

		// Sende Anfrage an die API mit Bearer Token
		$response = Image_AI_SEO_Api_Client::post(
			'/api/imageaiseo/optimize',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'image_url'           => $image_url,
						'focus_keyword'       => $focus_keyword,
						'additional_keywords' => $additional_keywords,
						'website_description' => $website_description,
						'language'            => $language,
					)
				),
				'timeout' => 30, // Längeres Timeout für Bildverarbeitung
			)
		);

		if ( is_wp_error( $response ) ) {
			// GDPR-01/Pitfall 3: a consent-required block is not a connection
			// error -- surface the actionable message instead of the generic one.
			// The stable `code` lets the bulk run stop at the first image instead of
			// failing (and counting an attempt for) every remaining image.
			if ( 'image_ai_seo_consent_required' === $response->get_error_code() ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
					'code'    => 'image_ai_seo_consent_required',
				);
			}

			// CRED-02/D-07: a 0-credit block is not a connection error either --
			// surface the actionable no-credits message and carry a stable `code`
			// key. Plan 07-04/07-06 branch on this exact string to render a buy-link
			// upsell CTA instead of a generic error.
			if ( 'image_ai_seo_no_credits' === $response->get_error_code() ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
					'code'    => 'image_ai_seo_no_credits',
				);
			}

			return array(
				'success' => false,
				'message' => __( 'Connection error. Please try again later.', 'altimo' ),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid response from server.', 'altimo' ),
			);
		}

		if ( $data['status'] === 'error' ) {
			return array(
				'success' => false,
				'message' => isset( $data['message'] )
					? sanitize_text_field( $data['message'] )
					: __( 'Error during image optimization.', 'altimo' ),
			);
		}

		if ( $data['status'] === 'success' ) {
			// Speichere die verbleibenden Credits
			if ( isset( $data['credits'] ) ) {
				update_option( 'image_ai_seo_remaining_credits', intval( $data['credits'] ) );
			}

			return array(
				'success' => true,
				'message' => __( 'Image successfully optimized! Don\'t forget to save the changes.', 'altimo' ),
				'data'    => array(
					'title'       => sanitize_text_field( $data['title'] ),
					'alt_text'    => sanitize_text_field( $data['alt_text'] ),
					'description' => sanitize_text_field( $data['description'] ),
					// D-14: sanitize_file_name() first (unchanged), then strip any extension
					// the API may have supplied so no downstream consumer can double-append it.
					'filename'    => pathinfo( sanitize_file_name( $data['filename'] ), PATHINFO_FILENAME ),
					'credits'     => isset( $data['credits'] ) ? intval( $data['credits'] ) : 0,
				),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Unexpected error during image optimization.', 'altimo' ),
		);
	}

	/**
	 * Schreibt einen Optimierungsvorschlag dauerhaft: Metadaten, Dateiname (mit Rollback
	 * bei Fehlschlag) und History-Log. Geteilter Rollback-Vertrag von
	 * optimize_single_image()/regenerate_image() (RESEARCH.md Pitfall 4) -- NUR für diese
	 * beiden Aufrufer verwenden, NICHT für run_auto_optimize()/bulk_optimize_image(), die
	 * ihre eigene, abweichende Schreibreihenfolge inline behalten (D-06).
	 *
	 * @param int    $image_id    Die ID des Bildes.
	 * @param array  $data        Vorschlagsdaten aus generate() (title, alt_text,
	 *                            description, filename, credits).
	 * @param array  $old_data    Zustand vor der Optimierung (Rollback-Basis), z. B. aus
	 *                            Image_AI_SEO_Metadata_Handler::get_image_metadata().
	 * @param string $action_type History-Log-Action-Type ('optimize' oder 'regenerate').
	 * @return array Response mit Status, Message, `notice` (nicht-fataler Hinweis, z. B.
	 *               die D-13-Offload-Verweigerung -- leer, wenn es keinen gibt) und (bei
	 *               Erfolg) den tatsächlich geschriebenen Daten inkl. des tatsächlichen
	 *               neuen Dateinamens.
	 *
	 * RENAME-03/D-13: Ein `offload_detected`-WP_Error aus rename_attachment() ist KEIN
	 * Fehlschlag. Titel, Alt-Text und Beschreibung bleiben geschrieben, nur der Dateiname
	 * bleibt unverändert; der Grund wird über `notice` an die UI durchgereicht. Jeder
	 * andere Fehlercode rollt die Metadaten unverändert wie bisher zurück.
	 */
	public static function apply( $image_id, $data, $old_data, $action_type = 'optimize' ) {
		// Speichere die optimierten Daten
		Image_AI_SEO_Metadata_Handler::update_image_metadata(
			$image_id,
			$data['title'],
			$data['alt_text'],
			isset( $data['description'] ) ? $data['description'] : ''
		);

		// Benenne die Datei um
		$old_file      = get_attached_file( $image_id );
		$new_filename  = $data['filename'];
		$rename_result = Image_AI_SEO_File_Handler::rename_attachment( $image_id, $new_filename, $old_file );
		$rename_notice = '';

		if ( is_wp_error( $rename_result ) ) {
			if ( 'offload_detected' === $rename_result->get_error_code() ) {
				// D-13: Ausgelagerte Medien behalten ihren Dateinamen, aber Titel,
				// Alt-Text und Beschreibung bleiben optimiert. Die Verweigerung ist
				// KEIN Fehlschlag -- sie wird als Hinweis mit der Erfolgsantwort
				// ausgeliefert (T-04-15: niemals stillschweigend verschlucken).
				$rename_notice = $rename_result->get_error_message();
			} else {
				// Echter Fehlschlag -- Metadaten wie bisher zurücksetzen.
				Image_AI_SEO_Metadata_Handler::update_image_metadata(
					$image_id,
					$old_data['title'],
					$old_data['alt_text'],
					$old_data['description']
				);
				Image_AI_SEO_Plugin::log(
					sprintf(
						'Altimo: Failed to rename file during apply() for attachment %d (%s): %s',
						$image_id,
						$rename_result->get_error_code(),
						$rename_result->get_error_message()
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Failed to rename file. Changes have been rolled back.', 'altimo' ),
				);
			}
		}

		// Hole den tatsächlichen neuen Dateinamen nach dem Rename
		$actual_new_filename = basename( get_attached_file( $image_id ) );

		// Logge die Änderung erst nach erfolgreichen Operationen
		$history_id = Image_AI_SEO_History_Handler::log_change(
			$image_id,
			$action_type,
			$old_data,
			array(
				'title'       => $data['title'],
				'alt_text'    => $data['alt_text'],
				'filename'    => $actual_new_filename,
				'description' => isset( $data['description'] ) ? $data['description'] : '',
			)
		);

		self::persist_rename_backup( $history_id, $rename_result );

		return array(
			'success' => true,
			'notice'  => $rename_notice,
			'data'    => array_merge( $data, array( 'filename' => $actual_new_filename ) ),
		);
	}

	/**
	 * D-04/D-16: Persistiert den vor der Umbenennung erfassten Referenz-Snapshot
	 * am zugehörigen History-Eintrag.
	 *
	 * Ohne diesen Schritt bleibt `backup_data` auf jeder Zeile NULL, und der vom
	 * Nutzer ausgelöste Revert stellt weiterhin nur Metadaten und Dateinamen
	 * wieder her -- genau der Defekt, den D-16 beheben soll. Der Snapshot lebte
	 * bisher ausschließlich im Speicher des Rename-Requests.
	 *
	 * Geteilter Hilfsschritt für alle drei Schreibpfade (apply(),
	 * bulk_optimize_image(), run_auto_optimize()), damit die drei nicht
	 * auseinanderlaufen können.
	 *
	 * @param int|false            $history_id    Rückgabe von log_change().
	 * @param array|WP_Error|mixed $rename_result Rückgabe von rename_attachment().
	 * @return void
	 */
	public static function persist_rename_backup( $history_id, $rename_result ) {
		if ( ! $history_id || ! is_array( $rename_result ) ) {
			return;
		}

		if ( empty( $rename_result['snapshot'] ) || ! is_array( $rename_result['snapshot'] ) ) {
			return;
		}

		Image_AI_SEO_History_Handler::store_backup( (int) $history_id, $rename_result['snapshot'] );
	}
}
