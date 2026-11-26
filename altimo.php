<?php
/**
 * Plugin Name: Altimo – AI Image SEO
 * Plugin URI: https://imageaiseo.com
 * Description: AI-written titles, alt texts, descriptions and file names for your images, in 28 languages.
 * Version: 3.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Creative Skyline Ltda.
 * Author URI: https://profiles.wordpress.org/creativeskyline/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: altimo
 * Domain Path: /languages
 */

// Direktzugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Altimo is the renamed Image AI SEO (3.0.0). It shares every constant,
// function, class, option and table with the old Image AI SEO and the
// discontinued Image AI SEO Pro copies, so their data carries over. When
// another copy already loaded in this request (or an older copy without the
// variant guard is still to come), this copy returns here, before it declares
// anything, and only shows a notice on the Plugins screen. The guard and its
// notice are shared too, so each is required only once per request.
if ( ! class_exists( 'Image_AI_SEO_Variant_Guard', false ) ) {
	require_once __DIR__ . '/includes/class-variant-guard.php';
}
if ( ! class_exists( 'Image_AI_SEO_Variant_Notice', false ) ) {
	require_once __DIR__ . '/includes/class-variant-notice.php';
}

$image_ai_seo_is_dormant = Image_AI_SEO_Variant_Guard::should_stay_dormant( __FILE__, 'free' );

// An old copy that loaded first brought its own, older guard, whose notice
// does not know Altimo: show Altimo's notice instead (no-op otherwise).
Image_AI_SEO_Variant_Notice::replace_older_guard_notice( $image_ai_seo_is_dormant );

if ( $image_ai_seo_is_dormant ) {
	unset( $image_ai_seo_is_dormant );
	return;
}
unset( $image_ai_seo_is_dormant );

// Plugin-Konstanten definieren
define( 'IMAGE_AI_SEO_EDITION', 'free' );
define( 'IMAGE_AI_SEO_VERSION', '3.0.0' );
define( 'IMAGE_AI_SEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMAGE_AI_SEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Lade die erforderlichen Dateien
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-api-client.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-consent-handler.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-credit-handler.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-consent-disclosure.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-file-handler.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-metadata-handler.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/class-admin-notices.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/class-admin-menu.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/class-network-admin-menu.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/class-admin-assets.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/class-media-columns.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/ajax/class-ajax-guard.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/ajax/class-ajax-optimize.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/ajax/class-ajax-metadata.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/ajax/class-ajax-history.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/ajax/class-ajax-statistics.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/ajax/class-ajax-site-profile.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-settings-handler.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-site-profile.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-activation.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-api-validator.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-seo-integration.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-image-optimizer.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-reference-updater.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-history-handler.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-statistics-handler.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-serialization-safe-replacer.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-rename-guard.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-redirect-manager.php';
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/class-plugin.php';

// Lifecycle callbacks. They live in their own file on purpose: PHP declares a
// file's unconditional top-level functions while compiling it, before the
// variant guard above could return, so a second copy of this file would end
// the request with "Cannot redeclare image_ai_seo_activate()".
require_once IMAGE_AI_SEO_PLUGIN_DIR . 'includes/lifecycle-functions.php';

// Plugin-Aktivierung
register_activation_hook( __FILE__, 'image_ai_seo_activate' );

// Plugin-Deaktivierung: geplantes History-Cleanup-Cron-Event entfernen
// (QUAL-07), damit kein verwaistes wiederkehrendes Event nach der
// Deaktivierung bestehen bleibt.
register_deactivation_hook( __FILE__, 'image_ai_seo_deactivate' );

// One-shot redirect to the Welcome tab after activation (2.2.0). The guards
// (AJAX, cron, bulk activation, network admin, WP-CLI, missing manage_options)
// live in Image_AI_SEO_Activation::get_redirect_url().
add_action( 'admin_init', 'image_ai_seo_activation_redirect' );


// Plugin starten
Image_AI_SEO_Plugin::init();
