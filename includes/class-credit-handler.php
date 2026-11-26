<?php
/**
 * Credit-state read API for the CRED-02/D-07 credit gate.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single sanctioned place that answers the credit-gate question (CRED-02/D-07).
 *
 * Before every outbound optimize request flowing through
 * Image_AI_SEO_Api_Client::post() stands the question "does this account still
 * have credits?" -- this class is the ONLY sanctioned place that answers it.
 * A second, independent credit check at any of the four optimize call sites
 * (single-image, bulk, auto-optimize-on-upload, History regenerate) is exactly
 * the failure mode the choke-point design (mirroring the Phase 6 consent gate)
 * exists to prevent.
 *
 * D-07: at 0 remaining credits all four optimize paths are blocked at the same
 * choke point Phase 6 used for consent, so a blocked attempt never marks an
 * image optimized and stays catchable once credits return.
 *
 * Pure read, no side effects: has_credits() reads the same option
 * ('image_ai_seo_remaining_credits') that Image_AI_SEO_Image_Optimizer::generate()
 * and Image_AI_SEO_API_Validator write from the API response. It NEVER calls
 * add_option()/update_option() as a read-time side effect (mirrors Pitfall 2 of
 * 06-RESEARCH.md for Image_AI_SEO_Consent_Handler). A missing option counts as
 * zero credits via the get_option() default.
 */
class Image_AI_SEO_Credit_Handler {

	/**
	 * Name of the site-level option holding the remaining credit balance. Written
	 * from the API response in Image_AI_SEO_Image_Optimizer::generate() and
	 * Image_AI_SEO_API_Validator; only ever read here for the gate decision.
	 */
	const OPTION_NAME = 'image_ai_seo_remaining_credits';

	/**
	 * Whether this account currently has at least one credit remaining.
	 *
	 * Pure read with a safe default: a missing option (fresh install, never
	 * validated), a zero, or a negative balance all count as "no credits". No
	 * add_option()/update_option() side effect (Pitfall 2 analogue).
	 *
	 * @return bool True when the remaining balance is greater than zero.
	 */
	public static function has_credits() {
		return (int) get_option( self::OPTION_NAME, 0 ) > 0;
	}

	/**
	 * Below this balance the dashboard header shows the "Low credits!" badge (D-05).
	 *
	 * Passed to admin-dashboard.js as `lowCreditsThreshold`, so the badge the
	 * server renders and the badge the script switches after a balance update
	 * use the same rule.
	 */
	const LOW_CREDITS_THRESHOLD = 10;

	/**
	 * Which credit badge the dashboard header shows for a balance.
	 *
	 * Without an API key the stored balance is not a known fact yet (the option
	 * only defaults to 0), so no badge is shown. The first validation returns the
	 * real balance and admin-dashboard.js then switches the badge, which keeps a
	 * stale "No credits remaining!" from a key-less page load from standing next
	 * to the fresh count, even if that script never runs.
	 *
	 * @param int  $credits     Remaining credit balance.
	 * @param bool $has_api_key Whether an API key is configured for this site.
	 * @return string 'empty', 'low' or '' (no badge).
	 */
	public static function get_warning_level( $credits, $has_api_key = true ) {
		if ( ! $has_api_key ) {
			return '';
		}

		$credits = (int) $credits;

		if ( $credits <= 0 ) {
			return 'empty';
		}

		if ( $credits < self::LOW_CREDITS_THRESHOLD ) {
			return 'low';
		}

		return '';
	}
}
