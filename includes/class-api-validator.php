<?php

defined( 'ABSPATH' ) || exit;

class Image_AI_SEO_API_Validator {

	/**
	 * Validiert den API-Key gegen den externen Service
	 *
	 * @param string $api_key Der zu validierende API-Key
	 * @return array Response mit Status und Nachricht
	 */
	public static function validate_api_key( $api_key ) {
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter an API key.', 'altimo' ),
			);
		}

		// Hole die Domain der Website
		$site_url = wp_parse_url( get_site_url(), PHP_URL_HOST );

		// Hole die Server-IP
		$server_ip = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		if ( empty( $server_ip ) && isset( $_SERVER['LOCAL_ADDR'] ) ) {
			$server_ip = sanitize_text_field( wp_unslash( $_SERVER['LOCAL_ADDR'] ) );
		}

		$response = Image_AI_SEO_Api_Client::post(
			'/api/imageaiseo/validate',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'domain'         => $site_url,
						'ip_address'     => $server_ip,
						'plugin_version' => IMAGE_AI_SEO_VERSION,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			// GDPR-01/Pitfall 3: a consent-required block is not a connection
			// error -- surface the actionable message instead of the generic one.
			if ( 'image_ai_seo_consent_required' === $response->get_error_code() ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
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

		if ( $data['status'] === 'success' ) {
			// Speichere API-Key und Credits in der Datenbank
			update_option( 'image_ai_seo_api_key', $api_key );
			update_option( 'image_ai_seo_remaining_credits', isset( $data['credits'] ) ? intval( $data['credits'] ) : 0 );

			return array(
				'success' => true,
				'message' => __( 'API key successfully validated!', 'altimo' ),
				'credits' => isset( $data['credits'] ) ? intval( $data['credits'] ) : 0,
			);
		}

		return array(
			'success' => false,
			'message' => isset( $data['message'] )
				? sanitize_text_field( $data['message'] )
				: __( 'API key could not be validated.', 'altimo' ),
		);
	}
}
