<?php

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet das Asset-Enqueueing (JS/CSS) des Plugins.
 *
 * Bündelt zwei nebeneinander bestehende Enqueue-Pfade, die unterschiedliche
 * Admin-Screens bedienen: den Dashboard-Screen (`toplevel_page_altimo`)
 * und die Medienbibliothek/das Media-Modal.
 */
class Image_AI_SEO_Admin_Assets {

	/**
	 * Initialisiert beide Enqueue-Hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_dashboard_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media_assets' ) );
	}

	/**
	 * Lädt die benötigten Admin-Styles und Scripts für den Dashboard-Screen
	 */
	public static function enqueue_dashboard_assets( $hook ) {
		if ( 'toplevel_page_altimo' !== $hook ) {
			return;
		}

		// Header script that stops the browser from jumping to the URL hash
		// before admin-dashboard.js has switched to the matching tab.
		self::enqueue_hash_scroll_guard();

		wp_enqueue_style(
			'image-ai-seo-admin-dashboard',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/css/admin-dashboard.css',
			array(),
			IMAGE_AI_SEO_VERSION
		);

		wp_enqueue_script(
			'image-ai-seo-admin-dashboard',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			array( 'jquery' ),
			IMAGE_AI_SEO_VERSION,
			true
		);

		wp_localize_script(
			'image-ai-seo-admin-dashboard',
			'imageAiSeoAdmin',
			self::get_dashboard_localized_data()
		);
	}

	/**
	 * Returns the localization payload for the dashboard bulk-run script.
	 *
	 * Extracted so the same payload can be reused when admin-dashboard.js is
	 * enqueued on the Media Library screen for the MEDIA-02 bulk action, where the
	 * identical optimizeNextImage() loop and its strings are required.
	 *
	 * @return array Localization data (ajaxurl, nonce, strings).
	 */
	public static function get_dashboard_localized_data() {
		return array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'image_ai_seo_nonce' ),
			'licenseUrl'  => admin_url( 'admin.php?page=altimo&tab=license' ),
			// 2.2.1: the header badges switch at the same balance as on the server.
			'credits'     => array(
				'lowThreshold' => Image_AI_SEO_Credit_Handler::LOW_CREDITS_THRESHOLD,
			),
			// 2.2.0: whether the one-time automatic AI suggestion of the Welcome
			// settings is still due; the Welcome tab starts it when it opens.
			'siteProfile' => array(
				'autoRun' => Image_AI_SEO_Site_Profile::is_auto_run_due(),
			),
			'strings'     => array(
				'success'                  => __( 'Changes saved!', 'altimo' ),
				'error'                    => __( 'Error saving.', 'altimo' ),
				'emptyKey'                 => __( 'Please enter an API key.', 'altimo' ),
				'connectionError'          => __( 'Connection error. Please try again later.', 'altimo' ),
				'settingsSaved'            => __( 'Settings saved successfully.', 'altimo' ),
				'settingsError'            => __( 'Error saving settings.', 'altimo' ),
				'remainingCredits'         => __( 'Remaining credits', 'altimo' ),
				'optimizing'               => __( 'Optimizing...', 'altimo' ),
				'startBulkOptimization'    => __( 'Start Bulk Optimization', 'altimo' ),
				'allImagesOptimized'       => __( 'All images optimized', 'altimo' ),
				// translators: 1: number of images to optimize, 2: number of credits that will be used (same as %1$d), 3: number of credits currently available. Replaced client-side.
				'bulkCreditInfo'           => __( 'Optimizing %1$d images will use %2$d credits. You have %3$d credits.', 'altimo' ),
				'bulkCreditInsufficient'   => __( 'Warning: Not enough credits for all images!', 'altimo' ),
				'noImagesToOptimize'       => __( 'No images to optimize.', 'altimo' ),
				'bulkOptimizationError'    => __( 'Error during bulk optimization.', 'altimo' ),
				'bulkOptimizationComplete' => __( 'All images have been optimized successfully!', 'altimo' ),
				// translators: %d: current image number, %total: total number of images in the run (replaced client-side, not via sprintf).
				'optimizingImage'          => __( 'Optimizing image %d of %total', 'altimo' ),
				// D-14: Fortsetzen eines unterbrochenen Bulk-Laufs.
				'bulkOptimizationResumed'  => __( 'Resuming interrupted run…', 'altimo' ),
				// UAT-Entscheidung "Erst fragen, dann fortsetzen": ein unterbrochener Lauf wird
				// beim Seitenaufruf NICHT mehr stillschweigend fortgesetzt. Jedes fortgesetzte
				// Bild kostet einen Credit, also echtes Geld, und wer einen Lauf bewusst
				// abgebrochen hat, soll dafür nicht das Dashboard meiden müssen.
				// translators: %d: number of images still pending, %total: total number of images in the run.
				'bulkResumePrompt'         => __( 'Interrupted run found: %d of %total images still pending. Resume?', 'altimo' ),
				'bulkResumeConfirm'        => __( 'Resume run', 'altimo' ),
				'bulkResumeDiscard'        => __( 'Discard run', 'altimo' ),
				'bulkResumeDiscarded'      => __( 'The interrupted run was discarded. No further credits were used.', 'altimo' ),
				// D-10/CRED-03: bulk pre-flight truncation prompt. Shown when the
				// selection exceeds the known credit balance, so a run never aborts
				// mid-batch. Placeholders are replaced client-side via String.replace()
				// (%total = selected images, %remaining = available credits).
				// translators: %total: number of selected images, %remaining: number of available credits.
				'bulkPreflightPrompt'      => __( 'You selected %total images, but only have %remaining credits. Optimize as many as your credits allow?', 'altimo' ),
				// translators: %remaining: number of images that can be optimized with the available credits.
				'bulkPreflightConfirm'     => __( 'Optimize the first %remaining', 'altimo' ),
				'bulkPreflightCancel'      => __( 'Cancel', 'altimo' ),
				// CRED-02/D-06: no-credits upsell CTA on the bulk tab. Reuses the exact
				// "Buy more credits" label already rendered in dashboard-header.php.
				'noCreditsUpsellMessage'   => __( 'You have no credits remaining. Purchase more to continue optimizing your images.', 'altimo' ),
				'buyMoreCredits'           => __( 'Buy more credits', 'altimo' ),
				// CR-05: Bilder, die nach MAX_ATTEMPTS ausgemustert wurden, werden am Ende des
				// Laufs benannt statt bei jedem Seitenaufruf erneut versucht.
				'bulkAttentionHeadline'    => __( 'These images need your attention:', 'altimo' ),
				// translators: %d: attachment ID of the image that was retired from the run.
				'bulkAttentionImage'       => __( 'Image #%d could not be processed even after multiple attempts.', 'altimo' ),
				'bulkNoticesHeadline'      => __( 'Notices for this run:', 'altimo' ),
				/*
				 * D-03: Ein Bild, das nicht verarbeitet werden konnte, bricht den Lauf nicht
				 * mehr ab, sondern erscheint als Hinweis. Bewusst anders formuliert als die
				 * Offload-Meldung aus D-13 ("wird extern gehostet ... wird weiterhin
				 * optimiert", ein reiner Hinweis): dieser Fall benennt das betroffene Bild
				 * und fordert den Nutzer ausdrücklich zum Nachsehen auf.
				 */
				// translators: %1$d: attachment ID of the image that was skipped; %2$s: reason why it needs attention.
				'bulkImageSkipped'         => __( 'Image #%1$d was skipped and needs your attention: %2$s', 'altimo' ),
				// History strings
				'historyOptimize'          => __( 'AI Optimization', 'altimo' ),
				'historyManual'            => __( 'Manual Edit', 'altimo' ),
				'historyRevert'            => __( 'Reverted', 'altimo' ),
				'historyRestore'           => __( 'Restored', 'altimo' ),
				'historyRegenerate'        => __( 'Regenerated', 'altimo' ),
				'title'                    => __( 'Title', 'altimo' ),
				'altText'                  => __( 'Alt-Text', 'altimo' ),
				'filename'                 => __( 'Filename', 'altimo' ),
				'revert'                   => __( 'Revert', 'altimo' ),
				'restore'                  => __( 'Restore', 'altimo' ),
				'regenerate'               => __( 'Regenerate', 'altimo' ),
				'reverted'                 => __( 'Reverted', 'altimo' ),
				'processing'               => __( 'Processing...', 'altimo' ),
				'confirmRevert'            => __( 'Are you sure you want to revert this change?', 'altimo' ),
				'confirmRestore'           => __( 'Are you sure you want to restore this change?', 'altimo' ),
				'confirmRegenerate'        => __( 'Are you sure you want to regenerate this image? This will use 1 credit.', 'altimo' ),
				// Batch and filter strings
				'selected'                 => __( 'selected', 'altimo' ),
				// translators: %d: number of selected history entries to revert.
				'confirmBatchRevert'       => __( 'Are you sure you want to revert %d selected changes?', 'altimo' ),
				'batchRevertSuccess'       => __( 'Successfully reverted.', 'altimo' ),
				'batchRevertError'         => __( 'Error during batch revert.', 'altimo' ),
				'noItemsSelected'          => __( 'No items selected.', 'altimo' ),
				// Error messages
				'errorInsufficientCredits' => __( 'Insufficient credits. Please purchase more credits to continue.', 'altimo' ),
				'errorInvalidApiKey'       => __( 'Invalid API key. Please check your license settings.', 'altimo' ),
				'errorNetworkTimeout'      => __( 'Network timeout. Please check your connection and try again.', 'altimo' ),
				'errorInvalidImage'        => __( 'Invalid image file. The file may be corrupted or unsupported.', 'altimo' ),
				'errorFilenameExists'      => __( 'A file with this name already exists. Please choose a different name.', 'altimo' ),
				'errorImageNotFound'       => __( 'Image not found. It may have been deleted.', 'altimo' ),
				'errorGeneric'             => __( 'An unexpected error occurred. Please try again.', 'altimo' ),
				// A-03: accessible activity-chart data table (visually hidden).
				'activityDataHeading'      => __( 'Activity data, last 7 days', 'altimo' ),
				'activityDataDay'          => __( 'Day', 'altimo' ),
				'activityDataCount'        => __( 'Optimizations', 'altimo' ),
				// Accessible name for the per-row history batch-select checkbox.
				'selectHistoryItem'        => __( 'Select this history entry', 'altimo' ),
				// Bulk run stopped by the consent gate (missing or outdated consent).
				'consentRequired'          => __( 'Please accept the data-processing notice before using this feature.', 'altimo' ),
				'goToLicenseTab'           => __( 'Go to License Tab', 'altimo' ),
				'consentCheckboxRequired'  => __( 'Please tick the consent checkbox first.', 'altimo' ),
				'revokeConsent'            => __( 'Revoke consent', 'altimo' ),
				// 2.2.0: AI suggestion of the Welcome settings.
				'siteProfileAnalysing'     => __( 'AI is analysing your site…', 'altimo' ),
				'siteProfileInserted'      => __( 'Suggestions inserted. Review them and click "Save Settings" to keep them.', 'altimo' ),
				'siteProfileOverwrite'     => __( 'The fields already contain text. Replace it with AI suggestions?', 'altimo' ),
				'siteProfileReplace'       => __( 'Replace with suggestions', 'altimo' ),
				'siteProfileKeep'          => __( 'Keep my text', 'altimo' ),
			),
		);
	}

	/**
	 * Enqueues the header script that keeps the browser from scrolling to the
	 * URL hash on page load.
	 *
	 * The hash is stored in window.imageAiSeoInitialHash for admin-dashboard.js
	 * (tab deep links) and removed from the address bar. It must run in the
	 * document head, before the page renders, so it is attached as an inline
	 * script to a source-less handle printed in the header instead of being
	 * echoed as a raw <script> tag.
	 *
	 * @return void
	 */
	public static function enqueue_hash_scroll_guard() {
		$handle = 'image-ai-seo-hash-guard';

		wp_register_script( $handle, false, array(), IMAGE_AI_SEO_VERSION, false );
		wp_enqueue_script( $handle );
		wp_add_inline_script( $handle, self::get_hash_scroll_guard_script() );
	}

	/**
	 * Returns the hash-scroll guard JavaScript (without <script> tags).
	 *
	 * @return string JavaScript source.
	 */
	public static function get_hash_scroll_guard_script() {
		return '(function(){'
			. 'if(window.location.hash){'
			. 'window.imageAiSeoInitialHash=window.location.hash.substring(1);'
			. 'if(history.replaceState){history.replaceState(null,null,window.location.pathname+window.location.search);}'
			. 'window.scrollTo(0,0);'
			. '}'
			. '})();';
	}

	/**
	 * Enqueues admin.css and admin.js on the screens that render the plugin's
	 * media UI.
	 *
	 * The full set (stylesheet, script, preview dialog) loads only on the
	 * Media Library (`upload.php`) and on the edit screen of an attachment.
	 * The edit screens of posts, pages and other post types get nothing from
	 * here; when such a screen (or any other one) has set up the media modal,
	 * only admin.js is added for the modal's attachment fields, never the
	 * stylesheet (see enqueue_modal_script()).
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_media_assets( $hook ) {
		$is_media_page = 'upload.php' === $hook || self::is_attachment_edit_screen( $hook );

		if ( ! $is_media_page ) {
			// Media modal on any other screen: script only, no stylesheet.
			if ( did_action( 'wp_enqueue_media' ) ) {
				self::enqueue_modal_script();
			}
			return;
		}

		wp_enqueue_style(
			'image-ai-seo-admin',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			IMAGE_AI_SEO_VERSION
		);

		wp_enqueue_script(
			'image-ai-seo-admin',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			IMAGE_AI_SEO_VERSION,
			true
		);

		wp_localize_script( 'image-ai-seo-admin', 'imageAiSeoAdmin', self::get_localized_script_data() );

		wp_enqueue_style(
			'image-ai-seo-admin',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			IMAGE_AI_SEO_VERSION
		);

		// PREV-01: emit the preview-dialog template once in the footer so
		// openPreviewDialog() (assets/js/admin.js) has a DOM node to populate.
		add_action( 'admin_footer', array( __CLASS__, 'render_preview_dialog' ) );

		self::maybe_enqueue_media_bulk_runner( $hook );
	}

	/**
	 * Whether the current screen edits a single attachment.
	 *
	 * The hooks post.php and post-new.php serve every post type, so the hook
	 * alone is not enough: the screen's post type must be `attachment`,
	 * otherwise the plugin's assets would load in the editor of every post
	 * and page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return bool
	 */
	private static function is_attachment_edit_screen( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof WP_Screen && 'attachment' === $screen->post_type;
	}

	/**
	 * Renders the hidden AI-suggestion preview dialog template (PREV-01).
	 *
	 * Emitted once per request in the admin footer on every screen where
	 * assets/js/admin.js is enqueued, so its openPreviewDialog() has a single
	 * canonical DOM node to populate and show. Guarded so the markup is never
	 * printed more than once even if both enqueue paths ran.
	 *
	 * @return void
	 */
	public static function render_preview_dialog() {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/preview-dialog.php';
	}

	/**
	 * Enqueues the shared bulk-run loop (admin-dashboard.js) on the Media Library
	 * screen only when a MEDIA-02 bulk-action hand-off is in progress.
	 *
	 * MEDIA-02 drives the exact same optimizeNextImage()/bulk_optimize_image loop as
	 * the dashboard tab, and that loop lives in admin-dashboard.js. Rather than fork
	 * it, the script is loaded here so its verbatim functions run against the
	 * selected IDs. It is loaded ONLY on upload.php and ONLY when the
	 * Image_AI_SEO_Media_Columns::BULK_IDS_QUERY_ARG redirect arg is present, so the
	 * Media Library behaves exactly as before on every other view.
	 *
	 * The localized global reuses the SAME `imageAiSeoAdmin` name as admin.js but
	 * carries the dashboard payload merged over the media payload, i.e. a strict
	 * superset. admin-dashboard.js enqueues after admin.js, so its (superset)
	 * definition wins and admin.js's own media strings remain intact.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	private static function maybe_enqueue_media_bulk_runner( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}

		$bulk_ids_arg = Image_AI_SEO_Media_Columns::BULK_IDS_QUERY_ARG;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check of a post-redirect navigation arg; the per-image bulk_optimize_image AJAX call enforces its own nonce, capability and credit gate.
		if ( ! isset( $_GET[ $bulk_ids_arg ] ) || '' === $_GET[ $bulk_ids_arg ] ) {
			return;
		}

		wp_enqueue_script(
			'image-ai-seo-admin-dashboard',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			array( 'jquery' ),
			IMAGE_AI_SEO_VERSION,
			true
		);

		$data            = self::get_dashboard_localized_data();
		$media           = self::get_localized_script_data();
		$data['strings'] = array_merge( $media['strings'], $data['strings'] );

		wp_localize_script( 'image-ai-seo-admin-dashboard', 'imageAiSeoAdmin', $data );
	}

	/**
	 * Enqueues admin.js for the media modal on screens outside the Media
	 * Library and the attachment edit screen.
	 *
	 * The stylesheet admin.css comes along because the preview dialog opened from the modal
	 * needs its fixed overlay above the modal. Every selector in admin.css
	 * carries the image-ai-seo- prefix, so the stylesheet cannot restyle the
	 * editor or any other part of the WordPress interface.
	 *
	 * @return void
	 */
	public static function enqueue_modal_script() {
		// Same handle as enqueue_media_assets(), so admin.js never loads twice.
		if ( wp_script_is( 'image-ai-seo-admin', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'image-ai-seo-admin',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			IMAGE_AI_SEO_VERSION,
			true
		);

		wp_localize_script( 'image-ai-seo-admin', 'imageAiSeoAdmin', self::get_localized_script_data() );

		wp_enqueue_style(
			'image-ai-seo-admin',
			IMAGE_AI_SEO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			IMAGE_AI_SEO_VERSION
		);

		// PREV-01: the Media Modal loads admin.js on non-media screens too, so the
		// preview-dialog template must be available there as well.
		add_action( 'admin_footer', array( __CLASS__, 'render_preview_dialog' ) );
	}

	/**
	 * Gibt die gemeinsamen Lokalisierungsdaten für Scripts zurück
	 *
	 * @return array Lokalisierungsdaten
	 */
	public static function get_localized_script_data() {
		return array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'image_ai_seo_nonce' ),
			'strings' => array(
				'success'                => __( 'Changes saved!', 'altimo' ),
				'error'                  => __( 'Error saving!', 'altimo' ),
				'remainingCredits'       => __( 'Remaining credits', 'altimo' ),
				'optimizationError'      => __( 'Error during optimization. Please try again.', 'altimo' ),
				'connectionError'        => __( 'Connection error. Please try again later.', 'altimo' ),
				'optimizing'             => __( 'Optimizing...', 'altimo' ),
				'optimize'               => __( 'Optimize with AI', 'altimo' ),
				'optimized'              => __( 'Optimized!', 'altimo' ),
				// D-02: reused for the preview dialog's "request a new suggestion"
				// confirmation, matching the same 1-credit tone as the History tab.
				'confirmRegenerate'      => __( 'Are you sure you want to regenerate this image? This will use 1 credit.', 'altimo' ),
				// CRED-02/D-06 (BLOCKER 2): no-credits upsell CTA copy for both
				// admin.js surfaces (the single-image preview flow and the quick-edit
				// optimize action). admin.js and admin-dashboard.js load independently,
				// so each localization array carries its own copy of this string; the
				// wording matches enqueue_dashboard_assets()'s bulk-tab equivalent.
				'noCreditsUpsellMessage' => __( 'You have no credits remaining. Purchase more to continue optimizing your images.', 'altimo' ),
				// CRED-02/D-06: reuses the exact "Buy more credits" label already
				// rendered in dashboard-header.php, per D-06.
				'buyMoreCredits'         => __( 'Buy more credits', 'altimo' ),
			),
		);
	}
}
