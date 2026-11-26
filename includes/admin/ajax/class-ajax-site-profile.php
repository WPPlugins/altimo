<?php
/**
 * AJAX controller for the AI suggestion of the Welcome settings.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin AJAX controller for the AI suggestion of the Welcome settings (2.2.0).
 *
 * Nonce, then manage_options (the Welcome settings are site settings), then
 * delegation to Image_AI_SEO_Site_Profile. The request to the service is sent
 * from PHP, so the API key never reaches the browser.
 */
class Image_AI_SEO_Ajax_Site_Profile {

	/**
	 * Registers `wp_ajax_image_ai_seo_suggest_site_profile`.
	 */
	public static function init() {
		add_action( 'wp_ajax_image_ai_seo_suggest_site_profile', array( __CLASS__, 'suggest_site_profile' ) );
	}

	/**
	 * AJAX handler.
	 *
	 * `mode=auto`: the one-time automatic run. Fills and saves only empty
	 * fields, responds with the stored values and the keys it filled.
	 * Anything else (`mode=manual`, the "Suggest with AI" button): returns the
	 * suggestion without saving; the user reviews it and clicks Save. A manual
	 * request also uses up a still queued automatic run, so the service is not
	 * asked twice for the same thing.
	 */
	public static function suggest_site_profile() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

		if ( 'auto' === $mode ) {
			$result = Image_AI_SEO_Site_Profile::run_auto();

			if ( ! $result['success'] ) {
				wp_send_json_error( self::error_payload( $result ) );
			}

			wp_send_json_success(
				array(
					'message' => $result['message'],
					'filled'  => isset( $result['filled'] ) ? $result['filled'] : array(),
					'values'  => isset( $result['values'] ) ? $result['values'] : array(),
				)
			);
		}

		// Only a request that can actually be sent uses up the automatic run.
		if ( Image_AI_SEO_Site_Profile::can_request() ) {
			Image_AI_SEO_Site_Profile::mark_auto_run_done();
		}

		$result = Image_AI_SEO_Site_Profile::request();

		if ( ! $result['success'] ) {
			wp_send_json_error( self::error_payload( $result ) );
		}

		wp_send_json_success(
			array(
				'suggestions' => $result['data'],
			)
		);
	}

	/**
	 * Error payload with the message and, where there is one, a stable code.
	 *
	 * @param array{message: string, code?: string} $result Failed result.
	 * @return array{message: string, code: string}
	 */
	private static function error_payload( array $result ) {
		return array(
			'message' => $result['message'],
			'code'    => isset( $result['code'] ) ? $result['code'] : '',
		);
	}
}
