<?php
/**
 * Self-contained 301-redirect layer for renamed attachment files.
 *
 * D-09: redirects are permanent -- nothing expires, nothing is auto-pruned.
 * D-10: chains collapse at WRITE time to a single hop (A->B then B->C yields
 *       A->C directly), never followed at read time.
 * D-11: the redirect fires in PHP on `template_redirect` at the 404, never
 *       via .htaccess.
 * D-12: every file-family member (main file + every size variant + every
 *       sibling format) gets its own row -- record_rename() is called once
 *       per member by the write phase (04-06), not once per attachment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_AI_SEO_Redirect_Manager {

	const TABLE_NAME = 'image_ai_seo_redirects';

	/**
	 * Creates the redirects table on plugin activation.
	 *
	 * Deliberately has NO blog_id column: relies purely on $wpdb->prefix's
	 * per-site table-naming convention, exactly like class-history-handler.php.
	 * That means Phase 5's multisite scoping (MULTI-03) needs zero schema
	 * migration -- only looping the activation hook over every site.
	 *
	 * Deliberately WITHOUT "IF NOT EXISTS": dbDelta() extracts the table name
	 * with preg_match( '|CREATE TABLE ([^ ]*)|', ... ) and would capture the
	 * literal word "IF", diff against a non-existent table and silently never
	 * emit the ALTER TABLE for columns added later. dbDelta() is idempotent on
	 * its own. Same fix as in class-history-handler.php.
	 *
	 * `created_at` has no database default, for the same reasons as the
	 * history table (DEFAULT CURRENT_TIMESTAMP on DATETIME is invalid before
	 * MySQL 5.6.5, and the database clock is not UTC): record_rename() writes
	 * it explicitly. dbDelta() leaves the column of an existing table as it
	 * is, see Image_AI_SEO_History_Handler::create_table().
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            old_path varchar(255) NOT NULL,
            old_path_hash char(32) NOT NULL,
            new_path varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY old_path_hash (old_path_hash),
            KEY attachment_id (attachment_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Records a rename mapping and collapses any existing chain onto it.
	 *
	 * Called once PER FILE-FAMILY MEMBER (D-12) by the rename write phase.
	 * $old_path/$new_path are RELATIVE to the uploads baseurl (e.g.
	 * "2026/07/bild-300x200.jpg"), never full URLs, so a later domain or
	 * protocol change never invalidates a stored redirect.
	 *
	 * @param int    $attachment_id The attachment this path belongs to.
	 * @param string $old_path      The previous relative path.
	 * @param string $new_path      The new relative path.
	 * @return bool True when the mapping was stored. False on any database
	 *              error -- a missing table, a truncated path, a lost
	 *              connection. The caller MUST inspect this: a silently lost
	 *              row is a silently lost 301, which is the one feature
	 *              D-11/D-12 exist to deliver.
	 */
	public static function record_rename( $attachment_id, $old_path, $new_path ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// D-10: collapse any existing chain that pointed AT $old_path -- after
		// this UPDATE, anyone who previously redirected to $old_path now
		// redirects straight to $new_path. Single hop, always.
		$collapsed = $wpdb->update(
			$table,
			array( 'new_path' => $new_path ),
			array( 'new_path' => $old_path )
		);

		if ( false === $collapsed ) {
			return false;
		}

		// D-10, loop guard: the collapse can turn an existing row into a
		// self-reference. A->B is stored, then a rename back B->A -- exactly
		// what revert_change()/restore_change() do, and what D-08's counter
		// suffix produces on a collision -- rewrites that row to A->A. Under
		// D-09 the row is permanent and never pruned, so as soon as path A
		// 404s later, maybe_redirect() would answer a request for A with a 301
		// to A: a redirect loop that browsers abort with ERR_TOO_MANY_REDIRECTS
		// and that a 301 caches for a long time. Degenerate rows are deleted
		// right where they are created.
		$deleted = $wpdb->query( "DELETE FROM {$table} WHERE old_path = new_path" );

		if ( false === $deleted ) {
			return false;
		}

		// A rename onto its own name is a no-op, not a redirect -- storing it
		// would recreate exactly the self-reference just deleted.
		if ( $old_path === $new_path ) {
			return true;
		}

		// D-09: insert (or refresh) the permanent mapping itself. No TTL, no
		// cleanup -- only the user can remove a row. created_at is set here
		// (UTC), the table has no database default; a refresh keeps the
		// time the mapping was first recorded.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (attachment_id, old_path, old_path_hash, new_path, created_at)
				 VALUES (%d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE new_path = VALUES(new_path), attachment_id = VALUES(attachment_id)",
				$attachment_id,
				$old_path,
				md5( $old_path ),
				$new_path,
				current_time( 'mysql', true )
			)
		);

		return false !== $inserted;
	}

	/**
	 * Self-registered on `template_redirect` (D-11): 301s a 404'd request for
	 * a formerly-valid uploads path to its current location.
	 *
	 * A cheap path-prefix check runs BEFORE any database work, so the vast
	 * majority of unrelated 404s (typos, bot scans, missing pages) never
	 * touch the redirects table.
	 */
	public static function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only parsed for its path component, never echoed or used as a redirect target.
		$request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
		$upload_dir   = wp_upload_dir();
		$uploads_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );

		// Cheap string check FIRST -- avoid a DB query on the vast majority of
		// 404s that have nothing to do with uploaded media.
		if ( ! $request_path || ! $uploads_path || 0 !== strpos( $request_path, $uploads_path ) ) {
			return;
		}

		$relative_path = ltrim( substr( $request_path, strlen( $uploads_path ) ), '/' );

		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE_NAME;
		$new_path = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT new_path FROM {$table} WHERE old_path_hash = %s",
				md5( $relative_path )
			)
		);

		// Second line of defence against the self-reference described in
		// record_rename(): a row written by an older plugin version (or by
		// anything else touching the table) must never be able to 301 a
		// request onto itself.
		if ( $new_path && $new_path !== $relative_path ) {
			wp_safe_redirect( trailingslashit( $upload_dir['baseurl'] ) . $new_path, 301 );
			exit;
		}
	}
}

/*
 * Priority 1, NOT the default 10: WordPress core registers its own
 * `redirect_canonical()` on `template_redirect` at priority 10 in
 * wp-includes/default-filters.php, which is loaded before any plugin. On a
 * pretty-permalink site a 404'd request for a missing file therefore hits
 * core's canonical trailing-slash 301 first (`.../old.jpg` ->
 * `.../old.jpg/`) and `exit`s, so this callback never ran and the plugin's
 * own 301 layer was dead on arrival on exactly the sites it exists for.
 * Confirmed against a live install in plan 04-09. Running first is cheap:
 * maybe_redirect() bails on `! is_404()` and on a non-uploads path before it
 * touches the database.
 */
add_action( 'template_redirect', array( 'Image_AI_SEO_Redirect_Manager', 'maybe_redirect' ), 1 );
