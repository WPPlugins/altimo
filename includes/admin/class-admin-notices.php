<?php

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet die Admin-Notices des Plugins.
 *
 * Every notice is limited to the screens where it is actionable (the
 * plugins list, the Media Library, the attachment edit screen and the
 * plugin's own page) and can be dismissed. The WordPress Dashboard and every
 * other core screen stay free of them (wordpress.org guideline 11). A
 * dismissal is stored per user, so it survives page loads; notices that
 * report a state change (a new disclosure text) use a key that changes with
 * that state, and the no-credits notice comes back after a week, because the
 * paused auto-optimization keeps skipping uploads.
 */
class Image_AI_SEO_Admin_Notices {

	/**
	 * User meta holding the dismissed notice keys and their dismissal times.
	 */
	const DISMISSED_META_KEY = 'image_ai_seo_dismissed_notices';

	/**
	 * Nonce action of the dismissal request.
	 */
	const DISMISS_NONCE_ACTION = 'image_ai_seo_dismiss_notice';

	/**
	 * How long a dismissed no-credits notice stays hidden.
	 */
	const NO_CREDITS_SNOOZE = WEEK_IN_SECONDS;


	/**
	 * Initialisiert die Notice-Hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'show_activation_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_reconsent_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_consent_required_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_no_credits_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_notice_style' ) );
		add_action( 'wp_ajax_image_ai_seo_dismiss_notice', array( __CLASS__, 'dismiss_notice' ) );
	}

	/**
	 * Screen IDs on which the plugin's notices may appear.
	 *
	 * `plugins` is the plugins list, `upload` the Media Library, `attachment`
	 * the edit screen of a single attachment (post.php for the attachment post
	 * type) and `toplevel_page_altimo` the plugin's own page. The
	 * WordPress Dashboard and all other admin screens are deliberately left
	 * out, so the notices (and their stylesheet and dismiss script) never load
	 * there.
	 *
	 * @return string[]
	 */
	public static function get_relevant_screen_ids() {
		return array( 'plugins', 'upload', 'attachment', 'toplevel_page_altimo' );
	}

	/**
	 * Whether the current admin screen is one where the notices are relevant.
	 *
	 * @param bool $include_plugin_page Whether the plugin's own page counts.
	 * @return bool
	 */
	public static function is_relevant_screen( $include_plugin_page = true ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		if ( ! $include_plugin_page && 'toplevel_page_altimo' === $screen->id ) {
			return false;
		}

		return in_array( $screen->id, self::get_relevant_screen_ids(), true );
	}

	/**
	 * Whether the current user dismissed the given notice.
	 *
	 * @param string $key        Notice key.
	 * @param int    $snooze_for Seconds a dismissal lasts, 0 for good.
	 * @return bool
	 */
	public static function is_dismissed( $key, $snooze_for = 0 ) {
		$dismissed = get_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, true );

		if ( ! is_array( $dismissed ) || ! isset( $dismissed[ $key ] ) ) {
			return false;
		}

		return 0 === $snooze_for || ( time() - (int) $dismissed[ $key ] ) < $snooze_for;
	}

	/**
	 * AJAX: stores the dismissal of one notice for the current user.
	 */
	public static function dismiss_notice() {
		check_ajax_referer( self::DISMISS_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No permission.', 'altimo' ) ) );
		}

		$key = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( $_POST['notice'] ) ) : '';

		if ( ! in_array( $key, self::get_dismissible_keys(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown notice.', 'altimo' ) ) );
		}

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::DISMISSED_META_KEY, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();

		$dismissed[ $key ] = time();
		update_user_meta( $user_id, self::DISMISSED_META_KEY, $dismissed );

		wp_send_json_success();
	}

	/**
	 * The notice keys a dismissal request may store.
	 *
	 * @return string[]
	 */
	public static function get_dismissible_keys() {
		$keys = array( 'activation', self::get_reconsent_key(), self::get_consent_required_key(), 'no-credits' );

		return $keys;
	}

	/**
	 * Key of the re-consent notice; changes with every new disclosure text.
	 *
	 * @return string
	 */
	public static function get_reconsent_key() {
		return sanitize_key( 'reconsent-' . str_replace( '.', '-', Image_AI_SEO_Consent_Handler::TEXT_VERSION ) );
	}

	/**
	 * Key of the "auto-optimization paused, no consent" notice.
	 *
	 * @return string
	 */
	public static function get_consent_required_key() {
		return sanitize_key( 'consent-required-' . str_replace( '.', '-', Image_AI_SEO_Consent_Handler::TEXT_VERSION ) );
	}

	/**
	 * Lädt das Stylesheet und das Dismiss-Script für die Notices, nur auf den
	 * Screens, auf denen sie erscheinen können.
	 */
	public static function enqueue_notice_style() {
		$is_notice_screen = self::is_relevant_screen();

		if ( ! $is_notice_screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'image-ai-seo-admin-notice',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/css/admin-notice.css',
			array(),
			IMAGE_AI_SEO_VERSION
		);

		wp_register_script( 'image-ai-seo-admin-notice', false, array( 'jquery', 'common' ), IMAGE_AI_SEO_VERSION, true );
		wp_enqueue_script( 'image-ai-seo-admin-notice' );
		wp_localize_script(
			'image-ai-seo-admin-notice',
			'imageAiSeoNotices',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::DISMISS_NONCE_ACTION ),
			)
		);
		wp_add_inline_script(
			'image-ai-seo-admin-notice',
			'jQuery(function($){'
			. '$(document).on("click",".image-ai-seo-dismissible .notice-dismiss",function(){'
			. 'var key=$(this).closest(".image-ai-seo-dismissible").data("notice");'
			. 'if(!key){return;}'
			. '$.post(imageAiSeoNotices.ajaxurl,{action:"image_ai_seo_dismiss_notice",nonce:imageAiSeoNotices.nonce,notice:key});'
			. '});'
			. '});'
		);
	}

	/**
	 * Zeigt einen Aktivierungs-Banner wenn kein API-Key vorhanden ist
	 */
	public static function show_activation_notice() {
		// Only for users who can act on the notice.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Prüfe ob API-Key vorhanden ist (D-01: Seiten-Schlüssel überschreibt
		// Netzwerk-Schlüssel, einzig aufgelöst in
		// Image_AI_SEO_Api_Client::get_active_api_key()).
		$api_key = Image_AI_SEO_Api_Client::get_active_api_key()['key'];
		if ( ! empty( $api_key ) ) {
			return;
		}

		// Only on the relevant screens, never on the plugin page itself (the
		// License tab is right there), and not after the user dismissed it.
		if ( ! self::is_relevant_screen( false ) || self::is_dismissed( 'activation' ) ) {
			return;
		}

		$activate_url = admin_url( 'admin.php?page=altimo&tab=license' );
		?>
		<div class="notice is-dismissible image-ai-seo-dismissible image-ai-seo-activation-notice" data-notice="activation">
			<div class="image-ai-seo-notice-content">
				<div class="notice-icon">
					<span class="dashicons dashicons-format-image"></span>
				</div>
				<div class="notice-text">
					<strong><?php esc_html_e( 'Altimo is almost ready!', 'altimo' ); ?></strong>
					<p><?php esc_html_e( 'Activate your license to start optimizing your images with AI. New accounts get free starter credits.', 'altimo' ); ?></p>
				</div>
				<div class="notice-actions">
					<a href="<?php echo esc_url( $activate_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Activate Now', 'altimo' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Tells the site owner that the data-processing notice changed and that
	 * optimization (manual, bulk and automatic) is paused until consent is
	 * given again (Image_AI_SEO_Consent_Handler::needs_reconsent()).
	 *
	 * Shown regardless of the auto-optimize setting: every outbound request is
	 * blocked, so every way of optimizing is affected.
	 */
	public static function show_reconsent_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! Image_AI_SEO_Consent_Handler::needs_reconsent() ) {
			return;
		}

		if ( ! self::is_relevant_screen() || self::is_dismissed( self::get_reconsent_key() ) ) {
			return;
		}

		$license_url = admin_url( 'admin.php?page=altimo&tab=license' );
		?>
		<div class="notice notice-warning is-dismissible image-ai-seo-dismissible image-ai-seo-reconsent-notice" data-notice="<?php echo esc_attr( self::get_reconsent_key() ); ?>">
			<div class="image-ai-seo-notice-content">
				<div class="notice-icon">
					<span class="dashicons dashicons-shield"></span>
				</div>
				<div class="notice-text">
					<strong><?php esc_html_e( 'Altimo: please confirm the updated data-processing notice', 'altimo' ); ?></strong>
					<p><?php esc_html_e( 'The description of what is sent to the service has changed. Optimization and AI suggestions are paused until you have read the updated notice and given your consent again on the License tab. Your API key, settings and history are kept.', 'altimo' ); ?></p>
				</div>
				<div class="notice-actions">
					<a href="<?php echo esc_url( $license_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Review notice', 'altimo' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Zeigt einen dauerhaften, nicht-blockierenden Hinweis im Plugin-Dashboard,
	 * wenn Auto-Optimierung aktiv ist, aber (noch) kein Consent erteilt wurde
	 * (D-09). Erklärt, warum Uploads still übersprungen werden, statt pro
	 * Bild eine Meldung einzublenden.
	 */
	public static function show_consent_required_notice() {
		// Only for users who can act on the notice.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Der Hinweis ist nur relevant, wenn Auto-Optimierung überhaupt aktiv
		// ist UND kein Consent vorliegt -- genau dann greift D-09's stilles
		// Überspringen.
		$auto_optimize = (bool) get_option( 'image_ai_seo_auto_optimize', false );
		if ( ! $auto_optimize || Image_AI_SEO_Consent_Handler::is_granted() ) {
			return;
		}

		// An outdated consent has its own, more specific notice.
		if ( Image_AI_SEO_Consent_Handler::needs_reconsent() ) {
			return;
		}

		if ( ! self::is_relevant_screen() || self::is_dismissed( self::get_consent_required_key() ) ) {
			return;
		}

		$activate_url = admin_url( 'admin.php?page=altimo&tab=license' );
		?>
		<div class="notice is-dismissible image-ai-seo-dismissible image-ai-seo-consent-notice" data-notice="<?php echo esc_attr( self::get_consent_required_key() ); ?>">
			<div class="image-ai-seo-notice-content">
				<div class="notice-icon">
					<span class="dashicons dashicons-warning"></span>
				</div>
				<div class="notice-text">
					<strong><?php esc_html_e( 'Auto-optimization is paused', 'altimo' ); ?></strong>
					<p><?php esc_html_e( 'Auto-optimize is enabled, but images uploaded right now are being skipped because data-processing consent has not been granted yet. Grant consent on the License tab to resume.', 'altimo' ); ?></p>
				</div>
				<div class="notice-actions">
					<a href="<?php echo esc_url( $activate_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to License Tab', 'altimo' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Zeigt einen dauerhaften, nicht-blockierenden Hinweis im Plugin-Dashboard,
	 * wenn Auto-Optimierung aktiv ist, aber keine Credits mehr vorhanden sind
	 * (D-07). Erklärt, warum Uploads still übersprungen werden.
	 *
	 * Spiegelt bewusst die Struktur von show_consent_required_notice() (gleicher
	 * admin_notices-Hook, gleiche Capability-Prüfung, gleiche dreiteilige Live-
	 * Bedingung), ersetzt aber die Consent-Prüfung durch die Credit-Prüfung und
	 * trägt eine DEUTLICH andere Formulierung (RESEARCH.md Pattern 1). Der Button
	 * führt zur D-06-Buy-Link-URL, NICHT zum License-Tab.
	 *
	 * D-07's "bleibt auffindbar, wird nie fälschlich als optimiert markiert"-
	 * Garantie wird hier NICHT erneut implementiert -- sie gilt bereits per
	 * Konstruktion über den Credit-Gate in Image_AI_SEO_Api_Client::post()
	 * (Plan 07-01). Diese Methode fügt nur die sichtbare Erklärung hinzu und
	 * berührt run_auto_optimize() bewusst nicht.
	 */
	public static function show_no_credits_notice() {
		// Only for users who can act on the notice.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Der Hinweis ist nur relevant, wenn Auto-Optimierung aktiv ist UND keine
		// Credits mehr vorhanden sind -- genau dann greift D-07's stilles
		// Überspringen am Credit-Gate.
		$auto_optimize = (bool) get_option( 'image_ai_seo_auto_optimize', false );
		if ( ! $auto_optimize || Image_AI_SEO_Credit_Handler::has_credits() ) {
			return;
		}

		if ( ! self::is_relevant_screen() || self::is_dismissed( 'no-credits', self::NO_CREDITS_SNOOZE ) ) {
			return;
		}

		$buy_url = 'https://imageaiseo.com/pricing';
		?>
		<div class="notice is-dismissible image-ai-seo-dismissible image-ai-seo-no-credits-notice" data-notice="no-credits">
			<div class="image-ai-seo-notice-content">
				<div class="notice-icon">
					<span class="dashicons dashicons-cart"></span>
				</div>
				<div class="notice-text">
					<strong><?php esc_html_e( 'Auto-optimization is paused: no credits left', 'altimo' ); ?></strong>
					<p><?php esc_html_e( 'Auto-optimize is enabled, but newly uploaded images are being skipped because your credit balance has reached zero. Buy more credits to resume optimizing your uploads automatically.', 'altimo' ); ?></p>
				</div>
				<div class="notice-actions">
					<a href="<?php echo esc_url( $buy_url ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Buy more credits', 'altimo' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}
}
