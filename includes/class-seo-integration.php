<?php
/**
 * SEO-plugin focus-keyword precedence resolver (SEO-01, D-11, D-12).
 *
 * Resolves the focus keyword that {@see Image_AI_SEO_Image_Optimizer::generate()}
 * sends to the optimize API. Instead of always reading the single global
 * `image_ai_seo_main_keyword` option, this looks up a per-post keyword from the
 * active SEO plugin in a fixed precedence order (D-11): Yoast -> RankMath ->
 * AIOSEO. The first plugin that is active AND has a keyword set on the image's
 * parent post wins.
 *
 * D-12 ("never worse than today"): if no SEO plugin is active, or an active
 * plugin has no keyword set for the post, or the attachment has no parent post,
 * the resolver falls back to the manual `image_ai_seo_main_keyword` option --
 * exactly the behavior that existed before this phase.
 *
 * NOTE on the AIOSEO branch ({@see self::get_aioseo_keyphrase()}): AIOSEO's
 * exact keyphrase storage schema is UNVERIFIED against a real installation
 * (07-RESEARCH.md Open Question 2, Pitfall 5 -- LOW confidence). The
 * implementation is a training-knowledge guess (custom table
 * `{$wpdb->prefix}aioseo_posts`, JSON `keyphrases` column). It is deliberately
 * isolated into its own method so it can be verified/corrected in Plan 07-02
 * Task 3 without touching the rest of the precedence chain. Per D-12, any
 * AIOSEO detection or parse failure degrades gracefully to the manual-keyword
 * fallback -- never worse than today.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static resolver for the active SEO plugin's focus keyword.
 */
class Image_AI_SEO_Seo_Integration {

	/**
	 * Resolves the focus keyword for an attachment following the D-11
	 * precedence chain (Yoast -> RankMath -> AIOSEO), falling back to the
	 * manual `image_ai_seo_main_keyword` option per D-12.
	 *
	 * This is the SOLE keyword-source resolver for `generate()`. Callers MUST
	 * go through this method rather than reading `image_ai_seo_main_keyword`
	 * directly, so the precedence logic lives in exactly one place (mirroring
	 * the single-resolver convention of
	 * {@see Image_AI_SEO_Api_Client::get_active_api_key()}).
	 *
	 * @param int $attachment_id The attachment whose parent post is inspected.
	 * @return string The resolved focus keyword, or the manual option value
	 *                (possibly empty) when no SEO-plugin keyword applies.
	 */
	public static function resolve_focus_keyword( $attachment_id ) {
		$post_id = wp_get_post_parent_id( $attachment_id );

		// D-12: no parent post -> nothing to look up, use the manual keyword.
		if ( ! $post_id ) {
			return get_option( 'image_ai_seo_main_keyword', '' );
		}

		$kw = self::get_post_focus_keyword( $post_id );
		if ( '' !== $kw ) {
			return $kw;
		}

		// D-12: active plugin(s) but no keyword for this post -> manual fallback.
		return get_option( 'image_ai_seo_main_keyword', '' );
	}

	/**
	 * Returns the focus keyword the active SEO plugin stores for a post,
	 * following the D-11 precedence chain (Yoast -> RankMath -> AIOSEO), or ''
	 * when no active plugin has one. Has no fallback of its own; that belongs
	 * to the caller (resolve_focus_keyword() falls back to the manual option,
	 * the activation prefill leaves the field empty).
	 *
	 * @param int $post_id The post to inspect.
	 * @return string The stored keyword as is, or ''.
	 */
	public static function get_post_focus_keyword( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return '';
		}

		// D-11: fixed order, first active plugin WITH a keyword set wins.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$kw = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
			if ( is_string( $kw ) && '' !== $kw ) {
				return $kw;
			}
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$kw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
			if ( is_string( $kw ) && '' !== $kw ) {
				return $kw;
			}
		}

		if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO' ) ) {
			$kw = self::get_aioseo_keyphrase( $post_id );
			if ( '' !== $kw ) {
				return $kw;
			}
		}

		return '';
	}

	/**
	 * Reads AIOSEO's focus keyphrase for a post.
	 *
	 * UNVERIFIED SCHEMA (07-RESEARCH.md Pitfall 5, Open Question 2): AIOSEO
	 * stores most of its SEO metadata in a custom table rather than postmeta.
	 * The best available training-knowledge guess is table
	 * `{$wpdb->prefix}aioseo_posts`, column `keyphrases` holding a JSON object
	 * of the shape `{"focus":{"keyphrase":"...","score":..},"additional":[...]}`.
	 * This is confirmed against a real AIOSEO install in Plan 07-02 Task 3.
	 *
	 * Safety (T-07-05, Information Disclosure): the direct `$wpdb` query is
	 * built exclusively with `$wpdb->prepare()` and the integer post ID --
	 * never string-concatenated SQL. The table name is checked for existence
	 * first so a missing table (AIOSEO active but table absent, or schema
	 * differs) degrades to an empty return rather than a SQL error, honoring
	 * D-12's "never worse than today".
	 *
	 * @param int $post_id The parent post ID.
	 * @return string The AIOSEO focus keyphrase, or '' when unavailable.
	 */
	private static function get_aioseo_keyphrase( $post_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		// Guard: if the custom table does not exist, degrade gracefully (D-12).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return '';
		}

		// T-07-05: parameterized query, integer post ID only.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// The identifier placeholder is unavailable on the pinned stack, so the
		// trusted table name (a non-user prefix plus a literal suffix) is
		// interpolated while the untrusted post ID stays a bound integer.
		$json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT keyphrases FROM `{$table}` WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id
			)
		);

		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		// Expected shape: { "focus": { "keyphrase": "...", ... }, ... }.
		if (
			isset( $decoded['focus']['keyphrase'] )
			&& is_string( $decoded['focus']['keyphrase'] )
		) {
			return $decoded['focus']['keyphrase'];
		}

		return '';
	}
}
