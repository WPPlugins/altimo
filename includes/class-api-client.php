<?php

defined( 'ABSPATH' ) || exit;
/**
 * Zentraler Choke-Point für sämtlichen ausgehenden HTTP-Verkehr zum Creative-Skyline-Backend.
 *
 * QUAL-03/D-09: Vor diesem Refactor riefen Image_AI_SEO_Image_Optimizer und
 * Image_AI_SEO_API_Validator jeweils selbst wp_remote_post() gegen einen eigenen,
 * hartcodierten Endpoint auf. Ab jetzt läuft jeder ausgehende Request über
 * Image_AI_SEO_Api_Client::post().
 *
 * D-10: Der Endpoint ist hart auf imageaiseo.com gesetzt, ohne Filter oder
 * Konstante zum Überschreiben (bewusste Nutzerentscheidung).
 * D-11: Der Endpoint existiert serverseitig noch nicht; alle Tests laufen gegen den
 * bestehenden pre_http_request-Mock aus Phase 1.
 * D-12: Bei Netzwerkfehlern, Timeouts und HTTP 429 wird mit wachsendem Abstand
 * wiederholt; bei 401/403 und jedem anderen HTTP-Status wird sofort zurückgegeben.
 */
class Image_AI_SEO_Api_Client {

	/**
	 * Basis-URL des Backends. D-10: hart codiert, kein Filter/Konstante-Override.
	 */
	const BASE_URL = 'https://imageaiseo.com';

	/**
	 * Maximale Anzahl an Wiederholungsversuchen bei transienten Fehlern (D-12, Claude's Discretion).
	 */
	const MAX_RETRIES = 3;

	/**
	 * Basis-Wartezeit in Millisekunden zwischen Wiederholungsversuchen (D-12, Claude's Discretion).
	 */
	const RETRY_BASE_DELAY_MS = 200;

	/**
	 * Sendet einen POST-Request an den Choke-Point-Endpoint, mit Retry/Backoff bei
	 * transienten Fehlern.
	 *
	 * Gibt das rohe wp_remote_post()-Ergebnis zurück (Array oder WP_Error). Der
	 * Aufrufer bleibt verantwortlich für is_wp_error()/json_decode() und jegliche
	 * Sanitisierung der Response (Trennung Transport vs. Validierung).
	 *
	 * GDPR-01/D-03: bevor überhaupt ein Transport-Versuch unternommen wird, prüft
	 * dieser einzige Choke-Point Image_AI_SEO_Consent_Handler::is_granted(). Ohne
	 * erteilten Consent verlässt kein Byte diese Methode -- unabhängig davon, von
	 * welcher der aktuell zwei Aufrufstellen (generate(), validate_api_key()) aus
	 * post() erreicht wurde.
	 *
	 * @param string $endpoint            Pfad relativ zu self::BASE_URL, z. B. '/api/imageaiseo/optimize'.
	 * @param array  $args                Argumente für wp_remote_post() (headers, body, timeout, ...).
	 * @param bool   $retry               Whether transient failures (transport errors such as a
	 *                                    timeout, and HTTP 429) are retried. False sends exactly
	 *                                    one request: for site-profile every attempt counts
	 *                                    against the daily limit, and a timeout may already have
	 *                                    been counted on the server. Other HTTP errors (4xx, 5xx)
	 *                                    are never retried, whatever this flag says.
	 * @return array|WP_Error Das rohe wp_remote_post()-Ergebnis, oder ein
	 *         'image_ai_seo_consent_required'-WP_Error, falls kein Consent vorliegt.
	 */
	public static function post( $endpoint, $args, $retry = true ) {
		if ( ! Image_AI_SEO_Consent_Handler::is_granted() ) {
			return new WP_Error(
				'image_ai_seo_consent_required',
				__( 'Please accept the data-processing notice before using this feature.', 'altimo' )
			);
		}

		// CRED-02/D-07: second early-return at the same choke point as the consent
		// gate above, scoped ONLY to the optimize endpoint. All four optimize paths
		// (single, bulk, auto-optimize-on-upload, History regenerate) funnel through
		// here, so one check covers them without touching any call site.
		//
		// The scoping is deliberate (D-08 dependency): a future credit-refresh /
		// validate call must stay reachable at 0 credits, otherwise a user at 0
		// credits could never detect a top-up and would be permanently deadlocked.
		// Blocking every endpoint would create exactly that deadlock, so only
		// '/api/imageaiseo/optimize' is gated on credits here.
		if ( '/api/imageaiseo/optimize' === $endpoint && ! Image_AI_SEO_Credit_Handler::has_credits() ) {
			return new WP_Error(
				'image_ai_seo_no_credits',
				__( 'You have no credits remaining. Please purchase more to continue.', 'altimo' )
			);
		}

		$attempt = 0;

		do {
			$response = wp_remote_post( self::BASE_URL . $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				++$attempt;
				if ( ! $retry || $attempt > self::MAX_RETRIES ) {
					return $response;
				}
				self::backoff( $attempt );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 429 === $code ) {
				++$attempt;
				if ( ! $retry || $attempt > self::MAX_RETRIES ) {
					return $response;
				}
				self::backoff( $attempt );
				continue;
			}

			// 401/403 oder jeder andere Code (inkl. 200 mit "keine Credits" im Body):
			// sofort zurück, kein Retry.
			return $response;
		} while ( true );
	}

	/**
	 * Wartet zwischen zwei Wiederholungsversuchen. Die Verzögerung ist über den Filter
	 * `image_ai_seo_retry_delay_ms` überschreibbar (Tests setzen ihn auf 0).
	 *
	 * @param int $attempt Nummer des aktuellen Versuchs (ab 1).
	 */
	private static function backoff( $attempt ) {
		$delay_ms = apply_filters( 'image_ai_seo_retry_delay_ms', self::RETRY_BASE_DELAY_MS * $attempt, $attempt );
		usleep( $delay_ms * 1000 );
	}

	/**
	 * Resolves the currently active API key per D-01: a site-level override
	 * (this site's own `image_ai_seo_api_key` option) always wins over the
	 * network-wide key (`image_ai_seo_network_api_key`, a network option).
	 *
	 * D-01/Pitfall 4 (05-RESEARCH.md): this is the ONLY place in the codebase
	 * permitted to resolve this precedence. Every caller that needs the active
	 * key -- for an outbound HTTP request or purely for display -- MUST go
	 * through this method instead of reading either option directly. A second,
	 * independent implementation of this fallback (however trivial it looks)
	 * is the exact failure mode Pitfall 4 warns about.
	 *
	 * @return array{key: string, scope: string} `scope` is one of 'site',
	 *         'network', or 'none'.
	 */
	public static function get_active_api_key() {
		$site_key = (string) get_option( 'image_ai_seo_api_key', '' );
		if ( '' !== $site_key ) {
			return array(
				'key'   => $site_key,
				'scope' => 'site',
			);
		}

		$network_key = (string) get_site_option( 'image_ai_seo_network_api_key', '' );
		if ( '' !== $network_key ) {
			return array(
				'key'   => $network_key,
				'scope' => 'network',
			);
		}

		return array(
			'key'   => '',
			'scope' => 'none',
		);
	}
}
