<?php
/**
 * D-13 offloaded-media detection and D-15 per-image rename lock.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static-only pre-flight guard for the rename write path: detects whether an
 * attachment's main file has been offloaded to remote/cloud storage, and
 * provides a cross-request atomic lock so two overlapping rename attempts on
 * the same attachment cannot interleave.
 */
class Image_AI_SEO_Rename_Guard {

	/**
	 * Lock lifetime in seconds. A lock older than this is considered stale
	 * and is not honored by a subsequent acquire_lock() call (lazy TTL
	 * expiry -- no cron dependency needed, see 04-RESEARCH.md Don't
	 * Hand-Roll).
	 */
	const LOCK_TTL = 300;

	/**
	 * Postmeta keys a media-offload plugin writes on an attachment it manages.
	 * Every one of them is a POSITIVE statement by that plugin that the file
	 * lives in remote storage -- unlike a merely missing local file, which has
	 * a dozen mundane explanations. [ASSUMED] per 04-RESEARCH.md: exact key
	 * names drift by plugin version.
	 *
	 * @var string[]
	 */
	private static $offload_meta_keys = array(
		'amazonS3_info',
		'_wp_attachment_metadata_cloudinary',
		'_shortpixel_cloud_offloaded',
	);

	/**
	 * Detects whether an attachment's main file has been offloaded to
	 * remote/cloud storage (D-13).
	 *
	 * Detection requires a CORROBORATING signal from an offload plugin. Two
	 * earlier heuristics were removed because both produced false positives
	 * that D-13 never asked for:
	 *
	 * - "the local file is missing" on its own. A deleted, moved or
	 *   never-written file is not an offload, but it produced the user-facing
	 *   message "Dieses Bild wird extern gehostet (Offload/CDN)" -- and,
	 *   because every caller treats offload_detected as a NON-failure, the run
	 *   was then reported as successful. A missing local file now falls
	 *   through to the ordinary preflight check in
	 *   Image_AI_SEO_File_Handler::verify(), which fails honestly with
	 *   'preflight_failed' and names the file.
	 * - a bare has_filter( 'wp_get_attachment_url' ). That filter is hooked by
	 *   countless plugins and themes, so combined with a mere class_exists()
	 *   it refused renames even where WP Offload Media is configured to keep
	 *   local copies.
	 *
	 * @param int    $post_id   Attachment post ID.
	 * @param string $file_path Absolute local path the attachment's
	 *                          metadata claims to own.
	 * @return bool True when the rename must be refused (offload detected).
	 */
	public static function detect_offload( $post_id, $file_path ) {
		// Signal 1: an offload plugin has claimed this attachment explicitly.
		foreach ( self::$offload_meta_keys as $meta_key ) {
			if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
				return true;
			}
		}

		// Signal 2: WP Offload Media (or a fork built on the same SDK) is
		// active AND the local copy is gone -- its "remove from local server"
		// mode. Both halves are required; neither alone is evidence.
		if ( ! file_exists( $file_path ) && class_exists( 'Amazon_S3_And_CloudFront' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Acquires a per-image rename lock (D-15). The lock lives in wp_options so
	 * it survives across the multiple HTTP requests a chunked bulk rename
	 * (D-14) spans -- unlike GET_LOCK() (MySQL-connection-scoped) or the
	 * Transients API (non-atomic without a persistent object cache).
	 *
	 * Deliberately NOT add_option(): that function first performs a
	 * non-atomic get_option() existence check and returns false from PHP if a
	 * value is already cached, and the SQL it then issues is an
	 * "INSERT ... ON DUPLICATE KEY UPDATE" upsert that never fails on a
	 * duplicate. Two concurrent requests could therefore both be told they had
	 * acquired the lock -- exactly the half-renamed interleaving D-15 exists
	 * to prevent. A guarded "INSERT IGNORE" whose affected-row count IS the
	 * lock decision puts the option_name UNIQUE index in charge instead.
	 *
	 * @param int $post_id Attachment post ID.
	 * @return string|WP_Error The owner token on success, or a WP_Error with
	 *                         code 'rename_locked' when a live lock is already
	 *                         held by another process.
	 */
	public static function acquire_lock( $post_id ) {
		global $wpdb;

		$option_name = self::lock_option_name( $post_id );
		$token       = self::build_lock_token();

		if ( self::insert_lock_row( $option_name, $token ) ) {
			return $token;
		}

		// The insert lost: a row already exists. If it is stale (its embedded
		// TTL has elapsed), delete exactly the row that was read -- a
		// value-guarded DELETE, so a lock refreshed by its owner between the
		// read and the delete is never removed -- and retry the atomic insert
		// once.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the options cache is exactly what must be bypassed for a lock decision.
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);

		if ( null !== $existing && self::is_stale( maybe_unserialize( $existing ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$option_name,
					$existing
				)
			);

			if ( $deleted ) {
				self::flush_option_cache( $option_name );

				if ( self::insert_lock_row( $option_name, $token ) ) {
					return $token;
				}
			}
		}

		return new WP_Error(
			'rename_locked',
			__( 'This image is currently being renamed by another process.', 'altimo' )
		);
	}

	/**
	 * Releases a previously acquired lock -- but only if it is still the same
	 * lock this caller acquired.
	 *
	 * The token comparison is what makes this safe: process A acquires, stalls
	 * past LOCK_TTL, process B treats the lock as stale, deletes it and
	 * acquires its own. When A finally finishes, an unconditional
	 * delete_option() would remove B's LIVE lock while B is mid-write, letting
	 * a third process enter concurrently. A mismatching token now releases
	 * nothing.
	 *
	 * @param int   $post_id Attachment post ID.
	 * @param mixed $lock    The owner token returned by acquire_lock(). Typed
	 *                       loosely on purpose: callers hold whatever
	 *                       acquire_lock() gave them, which is a WP_Error on
	 *                       the refusal path.
	 * @return bool True when this call actually released the lock.
	 */
	public static function release_lock( $post_id, $lock ) {
		global $wpdb;

		if ( ! is_string( $lock ) || '' === $lock ) {
			return false;
		}

		$option_name = self::lock_option_name( $post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the options cache is exactly what must be bypassed for a lock decision.
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);

		if ( null === $existing ) {
			return false;
		}

		$payload = maybe_unserialize( $existing );

		if ( ! is_array( $payload ) || ! isset( $payload['token'] ) || ! hash_equals( (string) $payload['token'], $lock ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option_name,
				$existing
			)
		);

		self::flush_option_cache( $option_name );

		return (bool) $deleted;
	}

	/**
	 * Inserts the lock row atomically. The option_name UNIQUE index decides:
	 * exactly one concurrent caller sees one affected row.
	 *
	 * @param string $option_name The deterministic lock option name.
	 * @param string $token       The owner token to embed.
	 * @return bool True when THIS call created the row.
	 */
	private static function insert_lock_row( $option_name, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option() is not atomic; see acquire_lock().
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$option_name,
				maybe_serialize( self::build_lock_payload( $token ) ),
				'no'
			)
		);

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		self::flush_option_cache( $option_name );

		return true;
	}

	/**
	 * Invalidates the object-cache entries the direct SQL above bypasses.
	 *
	 * `notoptions` matters as much as the option itself: a previous
	 * get_option() miss caches the name as "known absent", after which a row
	 * inserted by raw SQL would stay invisible to the Options API.
	 *
	 * @param string $option_name The lock option name.
	 * @return void
	 */
	private static function flush_option_cache( $option_name ) {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Builds a random, unguessable owner token.
	 *
	 * @return string
	 */
	private static function build_lock_token() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Builds the deterministic per-attachment lock option name.
	 *
	 * @param int $post_id Attachment post ID.
	 * @return string
	 */
	private static function lock_option_name( $post_id ) {
		return 'image_ai_seo_rename_lock_' . (int) $post_id;
	}

	/**
	 * Builds a fresh lock payload embedding the owner token, the acquisition
	 * time and the TTL.
	 *
	 * @param string $token The owner token.
	 * @return array
	 */
	private static function build_lock_payload( $token ) {
		return array(
			'token'       => $token,
			'acquired_at' => time(),
			'ttl'         => self::LOCK_TTL,
		);
	}

	/**
	 * Determines whether a stored lock payload's TTL has already elapsed.
	 * A malformed/unexpected payload shape is treated as stale (fail open
	 * toward allowing a new acquisition, never toward a permanent deadlock).
	 *
	 * @param mixed $lock_payload The stored lock option value.
	 * @return bool
	 */
	private static function is_stale( $lock_payload ) {
		if ( ! is_array( $lock_payload ) || ! isset( $lock_payload['acquired_at'], $lock_payload['ttl'] ) ) {
			return true;
		}

		return ( $lock_payload['acquired_at'] + $lock_payload['ttl'] ) < time();
	}
}
