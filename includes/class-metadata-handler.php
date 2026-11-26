<?php

defined( 'ABSPATH' ) || exit;

class Image_AI_SEO_Metadata_Handler {

	const OPTIMIZATION_META_KEY = '_image_ai_seo_optimized';

	/**
	 * Durable per-attachment flag for the "needs attention" Media Library status (D-09).
	 *
	 * Written the moment a bulk run retires an image after MAX_ATTEMPTS (see
	 * Image_AI_SEO_Ajax_Optimize::mark_progress_failed()). Unlike the ephemeral
	 * `image_ai_seo_bulk_rename_progress` option's `failed_ids` list -- which is
	 * deleted the instant a run finishes (RESEARCH.md Pitfall 3) -- this postmeta
	 * flag survives page reloads and finished runs, so the status column can keep
	 * showing "needs attention" long after the run is gone. It is cleared here in
	 * update_image_metadata() on any successful write, so a later retry, bulk
	 * re-run or single-image optimize un-sticks the image automatically.
	 *
	 * @var string
	 */
	const NEEDS_ATTENTION_META_KEY = '_image_ai_seo_needs_attention';

	/**
	 * Aktualisiert die Metadaten eines Bildes
	 *
	 * @param int    $post_id Die Post-ID des Bildes
	 * @param string $title Der neue Titel
	 * @param string $alt_text Der neue Alt-Text
	 * @param string $description Die neue Beschreibung (optional)
	 * @return void
	 */
	public static function update_image_metadata( $post_id, $title, $alt_text, $description = '' ) {
		// Update Titel
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $description,
			)
		);

		// Update Alt-Text
		update_post_meta( $post_id, '_wp_attachment_image_alt', $alt_text );

		// Markiere das Bild als optimiert
		self::mark_image_as_optimized( $post_id );

		// D-09/Pitfall 3: A successful write un-sticks any "needs attention" flag.
		// Every successful path (apply(), bulk_optimize_image(), run_auto_optimize())
		// funnels through this method, so clearing the flag here covers all of them
		// uniformly without touching the individual call sites.
		delete_post_meta( $post_id, self::NEEDS_ATTENTION_META_KEY );
	}

	/**
	 * Holt die aktuellen Metadaten eines Bildes
	 *
	 * @param int $post_id Die Post-ID des Bildes
	 * @return array Die Metadaten des Bildes
	 */
	public static function get_image_metadata( $post_id ) {
		$attachment = get_post( $post_id );
		return array(
			'title'        => $attachment->post_title,
			'alt_text'     => get_post_meta( $post_id, '_wp_attachment_image_alt', true ),
			'description'  => $attachment->post_content,
			'filename'     => pathinfo( get_attached_file( $post_id ), PATHINFO_BASENAME ),
			'is_optimized' => self::is_image_optimized( $post_id ),
		);
	}

	/**
	 * Markiert ein Bild als optimiert und aktualisiert die Statistik
	 *
	 * @param int $post_id Die Post-ID des Bildes
	 * @return void
	 */
	public static function mark_image_as_optimized( $post_id ) {
		// Prüfe, ob das Bild bereits als optimiert markiert wurde
		if ( ! self::is_image_optimized( $post_id ) ) {
			update_post_meta( $post_id, self::OPTIMIZATION_META_KEY, true );

			// Aktualisiere die Gesamtstatistik
			$total_optimized = (int) get_option( 'image_ai_seo_total_optimized', 0 );
			update_option( 'image_ai_seo_total_optimized', $total_optimized + 1 );
		}
	}

	/**
	 * Removes the "optimized" mark from an image and corrects the total
	 * counter, the inverse of mark_image_as_optimized(). Used when a change is
	 * undone and the image is back in its unoptimized state, so it shows as
	 * "Not optimized" again and a bulk run picks it up.
	 *
	 * @param int $post_id Attachment ID.
	 * @return void
	 */
	public static function unmark_image_as_optimized( $post_id ) {
		if ( ! self::is_image_optimized( $post_id ) ) {
			return;
		}

		delete_post_meta( $post_id, self::OPTIMIZATION_META_KEY );

		$total_optimized = (int) get_option( 'image_ai_seo_total_optimized', 0 );
		update_option( 'image_ai_seo_total_optimized', max( 0, $total_optimized - 1 ) );
	}

	/**
	 * Prüft, ob ein Bild bereits optimiert wurde
	 *
	 * @param int $post_id Die Post-ID des Bildes
	 * @return bool True wenn das Bild optimiert wurde, sonst false
	 */
	public static function is_image_optimized( $post_id ) {
		return (bool) get_post_meta( $post_id, self::OPTIMIZATION_META_KEY, true );
	}

	/**
	 * Holt die Gesamtanzahl der optimierten Bilder
	 *
	 * @return int Die Anzahl der optimierten Bilder
	 */
	public static function get_total_optimized_images() {
		return (int) get_option( 'image_ai_seo_total_optimized', 0 );
	}

	/**
	 * Holt die Statistiken über optimierte und nicht optimierte Bilder
	 *
	 * @return array Array mit Statistiken (total, optimized, unoptimized)
	 */
	public static function get_image_optimization_stats() {
		global $wpdb;

		// Hole die Gesamtanzahl der Bilder
		$total_images = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type LIKE %s",
				'image/%'
			)
		);

		// Hole die Anzahl der optimierten Bilder
		$optimized_images = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE %s
            AND pm.meta_key = %s
            AND pm.meta_value = '1'",
				'image/%',
				self::OPTIMIZATION_META_KEY
			)
		);

		$total_images     = (int) $total_images;
		$optimized_images = (int) $optimized_images;

		return array(
			'total'       => $total_images,
			'optimized'   => $optimized_images,
			'unoptimized' => $total_images - $optimized_images,
		);
	}
}
