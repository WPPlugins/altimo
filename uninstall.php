<?php
/**
 * Multisite-aware plugin uninstall routine (D-07, MULTI-03).
 *
 * WordPress does NOT run this plugin's own `require_once` chain before
 * executing this file -- it is loaded standalone by the Plugins screen's
 * "Delete" action (or `wp plugin uninstall`), with only WP core bootstrapped.
 * Consequently every table/option name below MUST be a hardcoded string
 * literal (Pitfall 2): the plugin's class constants that hold these names
 * elsewhere in the codebase are NOT available here. See 05-RESEARCH.md
 * "Architecture Patterns"
 * Pattern 4 and 05-PATTERNS.md's `uninstall.php` section for the verified
 * literal list this file is built from.
 *
 * D-07: network-wide uninstall cleans up ALL sites, not just the main site,
 * so no plugin data is left behind anywhere in the network.
 *
 * @package Image_AI_SEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'image_ai_seo_uninstall_other_variant_installed' ) ) {
	/**
	 * Whether another copy of this plugin is still installed.
	 *
	 * Altimo – AI Image SEO (3.0.0) is the renamed Image AI SEO. It shares
	 * every option, both tables and the API key with the old Image AI SEO and
	 * the discontinued Image AI SEO Pro, which is how it takes over an existing
	 * install. Deleting one copy while another one is still installed must
	 * keep all of it: the usual way to Altimo is "install Altimo, then delete
	 * the old plugin", and the other way round must not wipe the settings, the
	 * key and the history either.
	 *
	 * Checks the known folders (old Image AI SEO, Image AI SEO Pro and Altimo)
	 * and every installed plugin with the text domain of Altimo or of the old
	 * copies, and confirms each candidate on disk, because a bulk delete
	 * removes the folders one after the other while get_plugins() still
	 * returns its cached list. The copy that is being deleted never counts.
	 * The plugin folder is shared by the whole network, so the answer holds
	 * for every site of a multisite network.
	 *
	 * @return bool
	 */
	function image_ai_seo_uninstall_other_variant_installed() {
		$own_file     = realpath( __DIR__ . '/altimo.php' );
		$candidates   = array( 'image-ai-seo/image-ai-seo.php', 'image-ai-seo-pro/image-ai-seo.php', 'altimo/altimo.php' );
		$text_domains = array( 'image-ai-seo', 'altimo' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $basename => $plugin_data ) {
			if ( isset( $plugin_data['TextDomain'] ) && in_array( $plugin_data['TextDomain'], $text_domains, true ) ) {
				$candidates[] = (string) $basename;
			}
		}

		foreach ( array_unique( $candidates ) as $basename ) {
			$file = realpath( WP_PLUGIN_DIR . '/' . $basename );

			if ( false !== $file && $file !== $own_file && is_file( $file ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'image_ai_seo_uninstall_site' ) ) {
	/**
	 * Drop this plugin's two custom tables and delete its 11 site-scoped options
	 * for whichever site is "current" at the time this runs (the main site on a
	 * single-site install, or the currently-switched-to site inside the
	 * multisite loop below).
	 */
	function image_ai_seo_uninstall_site() {
		global $wpdb;

		$history_table  = $wpdb->prefix . 'image_ai_seo_history';
		$redirect_table = $wpdb->prefix . 'image_ai_seo_redirects';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names cannot be parameterized.
		$wpdb->query( "DROP TABLE IF EXISTS {$history_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names cannot be parameterized.
		$wpdb->query( "DROP TABLE IF EXISTS {$redirect_table}" );

		$site_options = array(
			'image_ai_seo_api_key',
			'image_ai_seo_remaining_credits',
			'image_ai_seo_auto_optimize',
			'image_ai_seo_language',
			'image_ai_seo_main_keyword',
			'image_ai_seo_additional_keywords',
			'image_ai_seo_website_description',
			'image_ai_seo_total_optimized',
			'image_ai_seo_db_version',
			'image_ai_seo_consent',
			'image_ai_seo_site_profile_auto_run',
		);

		foreach ( $site_options as $option_name ) {
			delete_option( $option_name );
		}

		/*
		 * Deliberate exception, not an oversight (DIST-04, Phase 9 Plan 05): the two
		 * attachment postmeta keys this plugin writes, `_image_ai_seo_optimized`
		 * (see Image_AI_SEO_Metadata_Handler::OPTIMIZATION_META_KEY) and
		 * `_image_ai_seo_needs_attention` (see
		 * Image_AI_SEO_Metadata_Handler::NEEDS_ATTENTION_META_KEY), are
		 * intentionally NOT deleted here. They live on individual attachment
		 * posts (user content), and WordPress.org's convention is that uninstall
		 * removes the plugin's own settings/data stores, not metadata attached to
		 * a site's existing content. Deleting them would also silently discard
		 * the record of which images were already optimized if the plugin is
		 * ever reinstalled. If a future requirement needs this metadata gone,
		 * add an explicit, separately-reviewed cleanup step -- do not "fix" this
		 * as a missing DELETE. See tests/integration/test-uninstall-multisite.php
		 * for a test asserting this postmeta positively survives uninstall.
		 */
	}
}

// Another copy (an old Image AI SEO or Image AI SEO Pro, or a second Altimo) still uses every option, table and the API key: keep it all.
if ( image_ai_seo_uninstall_other_variant_installed() ) {
	return;
}

if ( is_multisite() ) {
	$image_ai_seo_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $image_ai_seo_site_ids as $image_ai_seo_site_id ) {
		switch_to_blog( $image_ai_seo_site_id );
		image_ai_seo_uninstall_site();
		restore_current_blog();
	}

	// Network-scoped key (D-01/MULTI-02), cleaned up once, outside the loop.
	delete_site_option( 'image_ai_seo_network_api_key' );
} else {
	image_ai_seo_uninstall_site();
}
