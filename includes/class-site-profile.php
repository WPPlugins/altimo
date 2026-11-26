<?php
/**
 * AI suggestion of the Welcome settings (main keyword, additional keywords,
 * website description) through the site-profile endpoint.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the site-profile request, sends it through the consent-gated
 * Image_AI_SEO_Api_Client::post() choke point and turns the answer into three
 * sanitized values that fit the Welcome fields.
 *
 * Two ways to run it:
 * - automatically, once: the first successful API-key validation, or the
 *   first consent given for an already stored key, queues the run
 *   (queue_auto_run()); run_auto() fills only fields that are still empty
 *   and stores them through the regular Welcome save path. The option
 *   AUTO_RUN_OPTION remembers that it happened, so it never runs again on
 *   its own, not even after a failed attempt.
 * - on request: the "Suggest with AI" button on the Welcome tab (request()),
 *   which only returns the suggestion; the user reviews it and saves.
 *
 * The API key never leaves PHP: the browser calls the AJAX action, this class
 * sends the request.
 */
class Image_AI_SEO_Site_Profile {

	/**
	 * Endpoint path relative to Image_AI_SEO_Api_Client::BASE_URL.
	 */
	const ENDPOINT = '/api/imageaiseo/site-profile';

	/**
	 * Site option holding the state of the one-time automatic run.
	 * Missing: never queued. AUTO_RUN_PENDING: queued. AUTO_RUN_DONE: used up.
	 */
	const AUTO_RUN_OPTION = 'image_ai_seo_site_profile_auto_run';

	/**
	 * State: the automatic run is queued and has not started yet.
	 */
	const AUTO_RUN_PENDING = 'pending';

	/**
	 * State: the automatic run has started once (whatever the outcome) or the
	 * user requested a suggestion by hand.
	 */
	const AUTO_RUN_DONE = 'done';

	/**
	 * Maximum number of post, page and product titles sent as samples.
	 */
	const MAX_SAMPLE_TITLES = 20;

	/**
	 * Maximum length of one sample title (characters).
	 */
	const MAX_TITLE_LENGTH = 150;

	/**
	 * Request timeout in seconds. The service gives the AI call 10 s; this
	 * leaves room for the rest of the request. Must stay at 20 s or more.
	 */
	const TIMEOUT = 30;

	/**
	 * Maps the response/JSON keys to the site options they belong to.
	 *
	 * @return array<string, string>
	 */
	public static function get_field_options() {
		return array(
			'main_keyword'        => 'image_ai_seo_main_keyword',
			'additional_keywords' => 'image_ai_seo_additional_keywords',
			'website_description' => 'image_ai_seo_website_description',
		);
	}

	/**
	 * Whether a suggestion can be requested at all: a key is active and
	 * consent to the current disclosure text is given. The request itself is
	 * still gated by Image_AI_SEO_Api_Client::post(); this only drives the UI.
	 *
	 * @return bool
	 */
	public static function can_request() {
		$api_key = Image_AI_SEO_Api_Client::get_active_api_key();

		return '' !== $api_key['key'] && Image_AI_SEO_Consent_Handler::is_granted();
	}

	/**
	 * Queues the one-time automatic run, unless it was queued or used before
	 * (the option exists only as AUTO_RUN_PENDING or AUTO_RUN_DONE).
	 *
	 * @return void
	 */
	public static function queue_auto_run() {
		if ( false !== get_option( self::AUTO_RUN_OPTION, false ) ) {
			return;
		}

		update_option( self::AUTO_RUN_OPTION, self::AUTO_RUN_PENDING, false );
	}

	/**
	 * Whether the automatic run is queued and would do something now: a
	 * request is possible and at least one Welcome field is still empty.
	 *
	 * @return bool
	 */
	public static function is_auto_run_due() {
		return self::AUTO_RUN_PENDING === get_option( self::AUTO_RUN_OPTION, false )
			&& self::can_request()
			&& array() !== self::get_empty_fields();
	}

	/**
	 * Marks the automatic run as used up, so it never starts on its own again.
	 *
	 * @return void
	 */
	public static function mark_auto_run_done() {
		update_option( self::AUTO_RUN_OPTION, self::AUTO_RUN_DONE, false );
	}

	/**
	 * Current values of the three Welcome settings.
	 *
	 * @return array{main_keyword: string, additional_keywords: string, website_description: string}
	 */
	public static function get_current_values() {
		$values = array();

		foreach ( self::get_field_options() as $field => $option_name ) {
			$values[ $field ] = (string) get_option( $option_name, '' );
		}

		return $values;
	}

	/**
	 * Keys of the Welcome settings that are empty or hold only whitespace.
	 *
	 * @return string[]
	 */
	public static function get_empty_fields() {
		$empty = array();

		foreach ( self::get_current_values() as $field => $value ) {
			if ( '' === trim( $value ) ) {
				$empty[] = $field;
			}
		}

		return $empty;
	}

	/**
	 * The JSON body of the site-profile request.
	 *
	 * Every value here is named in the consent disclosure
	 * (Image_AI_SEO_Consent_Disclosure::get_site_profile_data_categories()).
	 * Every key is always present: `site_tagline` is the empty string (never
	 * null, never omitted) when the site has no tagline or core's default one;
	 * the service treats an empty value as missing.
	 *
	 * @return array{site_name: string, site_tagline: string, site_url: string, locale: string, language: string, is_woocommerce: bool, sample_titles: string[]}
	 */
	public static function build_request_body() {
		return array(
			'site_name'      => Image_AI_SEO_Activation::to_plain_text( (string) get_bloginfo( 'name' ) ),
			'site_tagline'   => Image_AI_SEO_Activation::get_meaningful_tagline(),
			'site_url'       => home_url(),
			'locale'         => get_locale(),
			// The same option and default the optimize request sends.
			'language'       => (string) get_option( 'image_ai_seo_language', 'en' ),
			'is_woocommerce' => class_exists( 'WooCommerce' ),
			'sample_titles'  => self::get_sample_titles(),
		);
	}

	/**
	 * Titles of the most recently published posts, pages and (with
	 * WooCommerce) products: plain text, at most MAX_TITLE_LENGTH characters
	 * each, at most MAX_SAMPLE_TITLES in total. Password-protected entries are
	 * left out.
	 *
	 * @return string[]
	 */
	public static function get_sample_titles() {
		$post_types = array( 'post', 'page' );

		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}

		// get_posts() runs with suppress_filters => true by default, so no
		// posts_* filter of another plugin can change which titles are sent.
		$posts = get_posts(
			array(
				'post_type'     => $post_types,
				'post_status'   => 'publish',
				'has_password'  => false,
				'numberposts'   => self::MAX_SAMPLE_TITLES,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'no_found_rows' => true,
			)
		);

		$titles = array();

		foreach ( $posts as $post ) {
			$title = Image_AI_SEO_Activation::to_plain_text( (string) $post->post_title );

			if ( '' === $title ) {
				continue;
			}

			$titles[] = trim( mb_substr( $title, 0, self::MAX_TITLE_LENGTH, 'UTF-8' ) );
		}

		return $titles;
	}

	/**
	 * Requests a suggestion from the service.
	 *
	 * @return array{success: bool, message: string, code?: string, data?: array{main_keyword: string, additional_keywords: string, website_description: string}}
	 */
	public static function request() {
		$api_key = Image_AI_SEO_Api_Client::get_active_api_key();

		if ( '' === $api_key['key'] ) {
			return array(
				'success' => false,
				'message' => __( 'No API key found. Please add an API key in the settings.', 'altimo' ),
				'code'    => 'image_ai_seo_no_api_key',
			);
		}

		// The consent gate inside post() runs before anything is sent. Exactly
		// one attempt: every attempt counts against the daily limit of 3, so
		// neither a timeout nor a 429 nor a 5xx is retried.
		$response = Image_AI_SEO_Api_Client::post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key['key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( self::build_request_body() ),
				'timeout' => self::TIMEOUT,
			),
			false
		);

		return self::parse_response( $response );
	}

	/**
	 * Turns the raw transport result into a result array.
	 *
	 * The body decides, not the HTTP status (API spec 2.2): an error message
	 * of the service is passed on verbatim, only sanitized.
	 *
	 * @param array|WP_Error $response Result of Image_AI_SEO_Api_Client::post().
	 * @return array{success: bool, message: string, code?: string, data?: array{main_keyword: string, additional_keywords: string, website_description: string}}
	 */
	public static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			if ( 'image_ai_seo_consent_required' === $response->get_error_code() ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
					'code'    => 'image_ai_seo_consent_required',
				);
			}

			return array(
				'success' => false,
				'message' => __( 'Connection error. Please try again later.', 'altimo' ),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$data      = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			return array(
				'success' => false,
				'message' => 429 === $http_code ? self::get_daily_limit_message() : __( 'Invalid response from server.', 'altimo' ),
			);
		}

		if ( 'error' === $data['status'] ) {
			$message = isset( $data['message'] ) && is_string( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';

			if ( '' === $message ) {
				$message = 429 === $http_code ? self::get_daily_limit_message() : __( 'The site analysis failed. Please try again later.', 'altimo' );
			}

			return array(
				'success' => false,
				'message' => $message,
			);
		}

		if ( 'success' !== $data['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid response from server.', 'altimo' ),
			);
		}

		$suggestion = self::sanitize_suggestion( $data );

		if ( '' === implode( '', $suggestion ) ) {
			return array(
				'success' => false,
				'message' => __( 'The AI did not return any suggestions. Please try again later.', 'altimo' ),
			);
		}

		return array(
			'success' => true,
			'message' => '',
			'data'    => $suggestion,
		);
	}

	/**
	 * Sanitizes the three suggested values and shortens each to its field's
	 * limit, so the regular save path accepts them.
	 *
	 * @param array<string, mixed> $data Decoded success response.
	 * @return array{main_keyword: string, additional_keywords: string, website_description: string}
	 */
	public static function sanitize_suggestion( array $data ) {
		$raw = array();

		foreach ( array_keys( self::get_field_options() ) as $field ) {
			$raw[ $field ] = isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) ? (string) $data[ $field ] : '';
		}

		return array(
			'main_keyword'        => Image_AI_SEO_Settings_Handler::fit_to_length( sanitize_text_field( $raw['main_keyword'] ), Image_AI_SEO_Settings_Handler::MAIN_KEYWORD_MAX_LENGTH ),
			'additional_keywords' => Image_AI_SEO_Settings_Handler::fit_to_length( sanitize_text_field( $raw['additional_keywords'] ), Image_AI_SEO_Settings_Handler::ADDITIONAL_KEYWORDS_MAX_LENGTH ),
			'website_description' => Image_AI_SEO_Settings_Handler::fit_to_length( sanitize_textarea_field( $raw['website_description'] ), Image_AI_SEO_Settings_Handler::WEBSITE_DESCRIPTION_MAX_LENGTH ),
		);
	}

	/**
	 * Runs the one-time automatic suggestion: fills the Welcome fields that
	 * are still empty and stores them through
	 * Image_AI_SEO_Settings_Handler::store_welcome_settings().
	 *
	 * The run is marked as used up before the request is sent, so neither a
	 * failure nor a timeout nor a second open tab can start it again.
	 *
	 * @return array{success: bool, message: string, code?: string, filled?: string[], values?: array<string, string>}
	 */
	public static function run_auto() {
		if ( self::AUTO_RUN_PENDING !== get_option( self::AUTO_RUN_OPTION, false ) ) {
			return array(
				'success' => false,
				'message' => '',
				'code'    => 'image_ai_seo_auto_run_not_pending',
			);
		}

		// Not ready yet (no key or no consent): keep it queued for later.
		if ( ! self::can_request() ) {
			return array(
				'success' => false,
				'message' => '',
				'code'    => 'image_ai_seo_auto_run_not_ready',
			);
		}

		self::mark_auto_run_done();

		if ( array() === self::get_empty_fields() ) {
			return array(
				'success' => true,
				'message' => '',
				'filled'  => array(),
				'values'  => self::get_current_values(),
			);
		}

		$result = self::request();

		if ( ! $result['success'] ) {
			return $result;
		}

		// Read again: the request takes a while, and a field the user filled
		// in the meantime must not be overwritten.
		$values = self::get_current_values();
		$filled = array();

		foreach ( self::get_empty_fields() as $field ) {
			if ( '' !== $result['data'][ $field ] ) {
				$values[ $field ] = $result['data'][ $field ];
				$filled[]         = $field;
			}
		}

		if ( array() !== $filled ) {
			$stored = Image_AI_SEO_Settings_Handler::store_welcome_settings(
				$values['main_keyword'],
				$values['additional_keywords'],
				$values['website_description']
			);

			if ( is_wp_error( $stored ) ) {
				return array(
					'success' => false,
					'message' => $stored->get_error_message(),
				);
			}
		}

		return array(
			'success' => true,
			'message' => array() === $filled ? '' : __( 'AI suggestions were added to the empty fields and saved. You can change them at any time.', 'altimo' ),
			'filled'  => $filled,
			'values'  => self::get_current_values(),
		);
	}

	/**
	 * Fallback text for a 429 without a usable message in the body. Mirrors
	 * the message the service sends for its daily site-analysis limit.
	 *
	 * @return string
	 */
	private static function get_daily_limit_message() {
		return __( 'You have reached today\'s limit for site analysis. Please try again tomorrow.', 'altimo' );
	}
}
