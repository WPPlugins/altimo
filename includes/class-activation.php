<?php
/**
 * Activation-time behaviour: the one-shot redirect to the Welcome tab and the
 * local prefill of the Welcome settings.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin does when it is activated, apart from creating its
 * tables (image_ai_seo_activate() in altimo.php).
 *
 * Nothing in this class sends a request: the prefill reads only data that is
 * already stored on the site (the tagline and, if an SEO plugin is active, the
 * focus keyword of the static front page). The AI suggestion of the Welcome
 * settings (Image_AI_SEO_Site_Profile) runs only after an API key was
 * validated with consent, never at activation.
 */
class Image_AI_SEO_Activation {

	/**
	 * Transient that marks the next admin page load for the Welcome redirect.
	 */
	const REDIRECT_TRANSIENT = 'image_ai_seo_activation_redirect';

	/**
	 * Seconds the redirect mark stays valid. The redirect is meant for the page
	 * load right after the activation, not for a visit hours later.
	 */
	const REDIRECT_TTL = 30;

	/**
	 * Core's default tagline before WordPress 6.1. It describes nothing, so it
	 * is never used as a website description and never sent to the service.
	 */
	const DEFAULT_TAGLINE = 'Just another WordPress site';

	/**
	 * Sets the redirect mark for a regular, interactive activation.
	 *
	 * A network-wide activation and an activation through WP-CLI get no
	 * redirect: there is no single site the user is looking at, and a CLI run
	 * must not send the next person who opens wp-admin somewhere unexpected.
	 *
	 * @param bool $network_wide Whether the plugin was activated for the whole network.
	 * @return void
	 */
	public static function flag_redirect( $network_wide ) {
		if ( $network_wide || self::is_cli_request() ) {
			return;
		}

		set_transient( self::REDIRECT_TRANSIENT, true, self::REDIRECT_TTL );
	}

	/**
	 * Decides whether this request is the one to redirect, and consumes the
	 * mark when it is the admin's next page load.
	 *
	 * AJAX and cron requests (the heartbeat, for example) neither redirect nor
	 * consume the mark, so the page load that follows still gets it. Every
	 * other request consumes it, also the ones that are not redirected (bulk
	 * activation, the network admin, WP-CLI, a user without manage_options),
	 * so the redirect can never fire later than intended.
	 *
	 * @return string The Welcome-tab URL to redirect to, or '' for no redirect.
	 */
	public static function get_redirect_url() {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return '';
		}

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return '';
		}

		delete_transient( self::REDIRECT_TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check of core's bulk-activation redirect arg, no state change.
		$is_bulk_activation = isset( $_GET['activate-multi'] );

		if ( $is_bulk_activation || self::is_cli_request() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return admin_url( 'admin.php?page=altimo&tab=welcome' );
	}

	/**
	 * Redirects to the Welcome tab once after activation (admin_init callback).
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		$url = self::get_redirect_url();

		if ( '' === $url ) {
			return;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Whether the current request runs through WP-CLI.
	 *
	 * The `image_ai_seo_is_cli_request` filter exists for the test suite, which
	 * cannot define the WP_CLI constant for a single test.
	 *
	 * @return bool
	 */
	public static function is_cli_request() {
		return (bool) apply_filters( 'image_ai_seo_is_cli_request', defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Prefills the Welcome settings of the current site from data the site
	 * already has, without any network call. A field that already holds text
	 * is never touched.
	 *
	 * - Website description: the site's tagline, unless it is empty or core's
	 *   old default tagline.
	 * - Main focus keyword: the focus keyword an active SEO plugin stores for
	 *   the static front page. Nothing else is used (the site name is not a
	 *   keyword), so the field usually stays empty.
	 * - Additional keywords: stay empty, there is no local source for them.
	 *
	 * @return void
	 */
	public static function prefill_welcome_settings() {
		if ( self::is_option_empty( 'image_ai_seo_website_description' ) ) {
			$description = Image_AI_SEO_Settings_Handler::fit_to_length(
				sanitize_textarea_field( self::get_meaningful_tagline() ),
				Image_AI_SEO_Settings_Handler::WEBSITE_DESCRIPTION_MAX_LENGTH
			);

			if ( '' !== $description ) {
				update_option( 'image_ai_seo_website_description', $description );
			}
		}

		if ( self::is_option_empty( 'image_ai_seo_main_keyword' ) ) {
			$main_keyword = self::get_front_page_focus_keyword();

			if ( '' !== $main_keyword ) {
				update_option( 'image_ai_seo_main_keyword', $main_keyword );
			}
		}
	}

	/**
	 * The site's tagline as plain text, or '' when it is empty or one of
	 * core's default taglines.
	 *
	 * @return string
	 */
	public static function get_meaningful_tagline() {
		$tagline = self::to_plain_text( (string) get_bloginfo( 'description' ) );

		if ( '' === $tagline ) {
			return '';
		}

		$default_taglines = array(
			self::DEFAULT_TAGLINE,
			// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- deliberately core's own translation of its default tagline.
			__( 'Just another WordPress site' ),
		);

		if ( in_array( $tagline, $default_taglines, true ) ) {
			return '';
		}

		return $tagline;
	}

	/**
	 * Focus keyword of the static front page as stored by the active SEO
	 * plugin, or '' when there is none.
	 *
	 * A keyword longer than the field allows is skipped rather than cut, so the
	 * field never receives half a keyword.
	 *
	 * @return string
	 */
	public static function get_front_page_focus_keyword() {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return '';
		}

		$front_page_id = (int) get_option( 'page_on_front' );

		if ( ! $front_page_id || 'publish' !== get_post_status( $front_page_id ) ) {
			return '';
		}

		$keywords = Image_AI_SEO_Seo_Integration::get_post_focus_keyword( $front_page_id );

		// Rank Math stores several comma-separated keywords; the first one is the primary keyword.
		$parts   = explode( ',', $keywords );
		$keyword = sanitize_text_field( $parts[0] );

		if ( '' === $keyword || mb_strlen( $keyword ) > Image_AI_SEO_Settings_Handler::MAIN_KEYWORD_MAX_LENGTH ) {
			return '';
		}

		return $keyword;
	}

	/**
	 * Decodes entities, removes tags and collapses whitespace.
	 *
	 * @param string $text Text as stored by WordPress.
	 * @return string
	 */
	public static function to_plain_text( $text ) {
		$text = wp_strip_all_tags( wp_specialchars_decode( $text, ENT_QUOTES ) );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether a site option is missing or holds only whitespace.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	private static function is_option_empty( $option_name ) {
		return '' === trim( (string) get_option( $option_name, '' ) );
	}
}
