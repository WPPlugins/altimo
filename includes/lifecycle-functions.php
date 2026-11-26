<?php
/**
 * Activation, deactivation and activation-redirect callbacks of the plugin.
 *
 * Required by altimo.php after the variant guard, never declared in the
 * main file itself: PHP declares a file's unconditional top-level functions at
 * compile time, before any early return, so a second copy of the main file
 * (Altimo next to an old Image AI SEO or Image AI SEO Pro copy) would end the
 * request with "Cannot redeclare". The hooks that point here are registered in
 * altimo.php.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activation hook: creates the plugin tables and prefills the Welcome fields.
 *
 * Network-wide activation (MULTI-01, D-05) provisions every existing site, a
 * single activation (also of one site on a multisite network) only the
 * current one. The schema version is stored so that
 * Image_AI_SEO_Plugin::maybe_upgrade_db() does not run dbDelta() a second time
 * after a fresh install; a plugin update never reaches this hook, that is what
 * the migration is for. It is stored only when both tables of the current site
 * really exist afterwards (Image_AI_SEO_Plugin::record_db_version()); after a
 * failed CREATE the option stays unset and maybe_upgrade_db() retries on the
 * next request. After a network-wide activation the check runs for the
 * current (main) site, whose tables image_ai_seo_provision_network() has just
 * created like those of every other site; the other sites record their own
 * version through maybe_upgrade_db() on their first request, as before.
 *
 * @param bool $network_wide Whether the plugin is activated for the whole network.
 * @return void
 */
function image_ai_seo_activate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		image_ai_seo_provision_network();
	} else {
		Image_AI_SEO_History_Handler::create_table();
		Image_AI_SEO_Redirect_Manager::create_table();
		// 2.2.0: local prefill of empty Welcome fields (tagline, front-page
		// focus keyword). Reads only site data, sends nothing.
		Image_AI_SEO_Activation::prefill_welcome_settings();
	}

	Image_AI_SEO_Plugin::record_db_version();

	// One-shot redirect to the Welcome tab (2.2.0), not set for a network-wide
	// or WP-CLI activation, see Image_AI_SEO_Activation::flag_redirect().
	Image_AI_SEO_Activation::flag_redirect( $network_wide );
}

/**
 * Creates the plugin tables on every existing site of a network (MULTI-01, D-05).
 *
 * Runs in batches on a large network (wp_is_large_network()), so the
 * activation does not run into a PHP timeout (05-RESEARCH.md, Pitfall 1).
 * Only calls the existing create_table() methods, no new DDL.
 *
 * @return void
 */
function image_ai_seo_provision_network() {
	$batch_size = wp_is_large_network() ? 50 : 0; // 0 means the get_sites() default, no limit.
	$args       = array( 'fields' => 'ids' );
	if ( $batch_size ) {
		$args['number'] = $batch_size;
	}

	$offset = 0;
	do {
		$args['offset'] = $offset;
		$site_ids       = get_sites( $args );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			Image_AI_SEO_History_Handler::create_table();
			Image_AI_SEO_Redirect_Manager::create_table();
			Image_AI_SEO_Activation::prefill_welcome_settings();
			restore_current_blog();
		}

		$batch_count = count( $site_ids );
		$offset     += $batch_count;
	} while ( $batch_size && $batch_count === $batch_size );
}

/**
 * Deactivation hook: removes the scheduled history cleanup event (QUAL-07), so
 * no orphaned recurring event survives the deactivation.
 *
 * @return void
 */
function image_ai_seo_deactivate() {
	$timestamp = wp_next_scheduled( 'image_ai_seo_history_cleanup_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'image_ai_seo_history_cleanup_cron' );
	}
}

/**
 * Admin-init callback of the one-shot redirect to the Welcome tab (2.2.0).
 *
 * @return void
 */
function image_ai_seo_activation_redirect() {
	Image_AI_SEO_Activation::maybe_redirect();
}
