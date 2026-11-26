<?php

defined( 'ABSPATH' ) || exit;

class Image_AI_SEO_Settings_Handler {

	/**
	 * Maximum length of the main focus keyword (characters).
	 */
	const MAIN_KEYWORD_MAX_LENGTH = 50;

	/**
	 * Maximum length of the additional keywords (characters).
	 */
	const ADDITIONAL_KEYWORDS_MAX_LENGTH = 150;

	/**
	 * Maximum length of the website description (characters).
	 */
	const WEBSITE_DESCRIPTION_MAX_LENGTH = 1000;

	/**
	 * Speichert die Plugin-Einstellungen
	 */
	public static function save_settings() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		// Checkbox-Wert korrekt verarbeiten
		$auto_optimize = isset( $_POST['auto_optimize'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['auto_optimize'] ) );
		$language      = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		// Validiere die Sprache
		$valid_languages = array(
			'ar',
			'zh',
			'cs',
			'da',
			'nl',
			'en',
			'fi',
			'fr',
			'de',
			'el',
			'he',
			'hi',
			'hu',
			'id',
			'it',
			'ja',
			'ko',
			'no',
			'pl',
			'pt',
			'ro',
			'ru',
			'es',
			'sv',
			'th',
			'tr',
			'uk',
			'vi',
		);

		if ( ! in_array( $language, $valid_languages, true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid language selected.', 'altimo' ),
				)
			);
		}

		// Speichere die Einstellungen
		update_option( 'image_ai_seo_auto_optimize', $auto_optimize );
		update_option( 'image_ai_seo_language', $language );

		wp_send_json_success(
			array(
				'message'       => __( 'Settings saved successfully.', 'altimo' ),
				'auto_optimize' => $auto_optimize,
			)
		);
	}

	/**
	 * Speichert die Welcome-Einstellungen
	 */
	public static function save_welcome_settings() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$main_keyword        = isset( $_POST['main_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['main_keyword'] ) ) : '';
		$additional_keywords = isset( $_POST['additional_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['additional_keywords'] ) ) : '';
		$website_description = isset( $_POST['website_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['website_description'] ) ) : '';

		$stored = self::store_welcome_settings( $main_keyword, $additional_keywords, $website_description );

		if ( is_wp_error( $stored ) ) {
			wp_send_json_error(
				array(
					'message' => $stored->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved successfully.', 'altimo' ),
			)
		);
	}

	/**
	 * Sanitizes, length-checks and stores the three Welcome settings.
	 *
	 * The single save path for these options: the Welcome tab's save button
	 * (save_welcome_settings()) and the automatic AI suggestion
	 * (Image_AI_SEO_Site_Profile::run_auto()) both go through here, so the
	 * same sanitization and the same limits apply to both. Nothing is stored
	 * when one of the values is too long.
	 *
	 * @param string $main_keyword        Main focus keyword.
	 * @param string $additional_keywords Additional keywords.
	 * @param string $website_description Website description.
	 * @return true|WP_Error True when stored, a WP_Error naming the field that is too long otherwise.
	 */
	public static function store_welcome_settings( $main_keyword, $additional_keywords, $website_description ) {
		$main_keyword        = sanitize_text_field( (string) $main_keyword );
		$additional_keywords = sanitize_text_field( (string) $additional_keywords );
		$website_description = sanitize_textarea_field( (string) $website_description );

		// Validiere die Längen
		if ( mb_strlen( $main_keyword, 'UTF-8' ) > self::MAIN_KEYWORD_MAX_LENGTH ) {
			return new WP_Error( 'image_ai_seo_main_keyword_too_long', __( 'The main keyword must be less than 50 characters.', 'altimo' ) );
		}

		if ( mb_strlen( $additional_keywords, 'UTF-8' ) > self::ADDITIONAL_KEYWORDS_MAX_LENGTH ) {
			return new WP_Error( 'image_ai_seo_additional_keywords_too_long', __( 'The additional keywords must be less than 150 characters.', 'altimo' ) );
		}

		if ( mb_strlen( $website_description, 'UTF-8' ) > self::WEBSITE_DESCRIPTION_MAX_LENGTH ) {
			return new WP_Error( 'image_ai_seo_description_too_long', __( 'The description must be less than 1000 characters.', 'altimo' ) );
		}

		// Speichere die Einstellungen
		update_option( 'image_ai_seo_main_keyword', $main_keyword );
		update_option( 'image_ai_seo_additional_keywords', $additional_keywords );
		update_option( 'image_ai_seo_website_description', $website_description );

		return true;
	}

	/**
	 * Shortens a text to at most $max_length characters (UTF-8 characters, not
	 * bytes, like the service), preferably at the last
	 * separator (whitespace, comma or semicolon) inside the limit, so neither a
	 * word nor a keyword of a comma-separated list is cut in half. A text
	 * without any separator inside the limit (e.g. Japanese or Chinese text) is
	 * cut hard.
	 *
	 * @param string $text       Text to shorten.
	 * @param int    $max_length Maximum number of characters.
	 * @return string The text, trimmed and at most $max_length characters long.
	 */
	public static function fit_to_length( $text, $max_length ) {
		$text = trim( (string) $text );

		if ( mb_strlen( $text, 'UTF-8' ) <= $max_length ) {
			return $text;
		}

		$cut       = mb_substr( $text, 0, $max_length, 'UTF-8' );
		$next_char = mb_substr( $text, $max_length, 1, 'UTF-8' );

		// Only look for an earlier boundary when the cut lands inside a word.
		if ( ! preg_match( '/[\s,;]/u', $next_char ) && preg_match( '/^(.*\S)[\s,;]/us', $cut, $matches ) ) {
			$cut = $matches[1];
		}

		return rtrim( $cut, " \t\n\r\0\x0B,;" );
	}

	/**
	 * Validiert den API-Key gegen das Backend.
	 *
	 * 1:1 aus `ImageAiSeo::validate_api_key()` (image-ai-seo.php:322-351) übernommen,
	 * keine Verhaltensänderung (D-06).
	 *
	 * GDPR-01/D-03: Ohne gesetztes Consent-Häkchen wird der Schlüssel weder gespeichert
	 * noch validiert -- die Validierung selbst ist bereits ein ausgehender Request. Ist
	 * das Häkchen gesetzt, wird Consent VOR dem Aufruf des Validators erteilt (D-01: im
	 * Moment der Schlüssel-Eingabe, nicht erst beim ersten tatsächlichen Optimieren).
	 */
	public static function validate_api_key() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		// Checkbox-Wert korrekt verarbeiten (gleiche Konvention wie save_settings()'s
		// auto_optimize: der Boolean-String 'true', nicht '1'/'0').
		$consent_checked = isset( $_POST['consent'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['consent'] ) );

		if ( ! $consent_checked ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please accept the data-processing notice to validate and save your API key.', 'altimo' ),
				)
			);
		}

		Image_AI_SEO_Consent_Handler::grant( get_current_user_id() );

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		$result = Image_AI_SEO_API_Validator::validate_api_key( $api_key );

		if ( $result['success'] ) {
			// 2.2.0: the first successful validation queues the one-time AI
			// suggestion of the Welcome settings; the dashboard runs it right away.
			Image_AI_SEO_Site_Profile::queue_auto_run();

			wp_send_json_success(
				array(
					'message'               => $result['message'],
					'credits'               => $result['credits'],
					'site_profile_auto_run' => Image_AI_SEO_Site_Profile::is_auto_run_due(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}
	}

	/**
	 * Grants consent on this site for the API key that is already stored,
	 * without sending or overwriting the key.
	 *
	 * The License tab only shows a masked copy of a stored key, so the
	 * "Validate & Save" button cannot resend it. This handler covers the cases
	 * in which only the consent is missing: after a revocation, and after the
	 * disclosure text changed (Image_AI_SEO_Consent_Handler::needs_reconsent()).
	 * Nothing leaves the site here; the next optimization or balance check is
	 * the first outbound request under the new consent.
	 */
	public static function grant_consent() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$consent_checked = isset( $_POST['consent'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['consent'] ) );

		if ( ! $consent_checked ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please tick the consent checkbox first.', 'altimo' ),
				)
			);
		}

		Image_AI_SEO_Consent_Handler::grant( get_current_user_id() );

		// 2.2.0: installations that already had a key (and only re-consent to
		// the new disclosure text) get the one-time AI suggestion as well.
		// queue_auto_run() never re-queues a run that is pending or done.
		Image_AI_SEO_Site_Profile::queue_auto_run();

		wp_send_json_success(
			array(
				'message'               => __( 'Consent saved. You can optimize images again.', 'altimo' ),
				'site_profile_auto_run' => Image_AI_SEO_Site_Profile::is_auto_run_due(),
			)
		);
	}

	/**
	 * Widerruft Consent auf dieser Seite (D-06/D-07). Kippt ausschließlich das
	 * Consent-Flag um -- Titel, Alt-Texte, Beschreibungen, Dateinamen und die
	 * vollständige History (inkl. Revert) bleiben unangetastet. Nur künftige
	 * ausgehende Requests (Optimierung/Schlüssel-Validierung) werden dadurch
	 * gesperrt (D-08).
	 */
	public static function revoke_consent() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		Image_AI_SEO_Consent_Handler::revoke( get_current_user_id() );

		wp_send_json_success(
			array(
				'message' => __( 'Consent revoked. New optimizations are now blocked.', 'altimo' ),
			)
		);
	}

	/**
	 * Initialisiert die AJAX-Handler
	 */
	public static function init() {
		add_action( 'wp_ajax_image_ai_seo_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_image_ai_seo_save_welcome_settings', array( __CLASS__, 'save_welcome_settings' ) );
		add_action( 'wp_ajax_image_ai_seo_validate_api_key', array( __CLASS__, 'validate_api_key' ) );
		add_action( 'wp_ajax_image_ai_seo_revoke_consent', array( __CLASS__, 'revoke_consent' ) );
		add_action( 'wp_ajax_image_ai_seo_grant_consent', array( __CLASS__, 'grant_consent' ) );
	}
}
