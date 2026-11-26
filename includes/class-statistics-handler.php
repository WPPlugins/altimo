<?php

defined( 'ABSPATH' ) || exit;

class Image_AI_SEO_Statistics_Handler {

	/**
	 * Holt alle Statistiken für das Dashboard
	 *
	 * @return array Die Statistiken
	 */
	public static function get_all_statistics() {
		return array(
			'images'          => self::get_image_statistics(),
			'credits'         => self::get_credit_statistics(),
			'history'         => self::get_history_statistics(),
			'recent_activity' => self::get_recent_activity(),
		);
	}

	/**
	 * Holt Bild-Statistiken
	 *
	 * @return array Bild-Statistiken
	 */
	public static function get_image_statistics() {
		global $wpdb;

		// Gesamtanzahl Bilder
		$total_images = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%'"
		);

		// Optimierte Bilder (haben das Meta-Flag)
		$optimized_images = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             AND pm.meta_key = '_image_ai_seo_optimized'
             AND pm.meta_value = '1'"
		);

		$unoptimized_images = $total_images - $optimized_images;
		$optimization_rate  = $total_images > 0 ? round( ( $optimized_images / $total_images ) * 100, 1 ) : 0;

		return array(
			'total'             => $total_images,
			'optimized'         => $optimized_images,
			'unoptimized'       => $unoptimized_images,
			'optimization_rate' => $optimization_rate,
		);
	}

	/**
	 * Holt Credit-Statistiken
	 *
	 * "Today" and "this month" are UTC calendar periods. `created_at` is
	 * stored in UTC (see Image_AI_SEO_History_Handler::log_change()), so the
	 * bounds are computed in PHP with gmdate() instead of the database's
	 * CURRENT_DATE(), which follows the server's session time zone. On a site
	 * whose time zone is not UTC the day therefore starts at 00:00 UTC, not
	 * at local midnight; the same convention as get_recent_activity().
	 *
	 * @return array Credit-Statistiken
	 */
	public static function get_credit_statistics() {
		global $wpdb;

		$remaining_credits = (int) get_option( 'image_ai_seo_remaining_credits', 0 );

		// Credits verbraucht (aus History zählen)
		$table_name = $wpdb->prefix . 'image_ai_seo_history';

		// Prüfe ob die Tabelle existiert
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name;

		if ( ! $table_exists ) {
			return array(
				'remaining'       => $remaining_credits,
				'used_total'      => 0,
				'used_this_month' => 0,
				'used_today'      => 0,
			);
		}

		// Gesamt verbrauchte Credits (jede Optimierung = 1 Credit)
		$used_total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_name
             WHERE action_type IN ('optimize', 'regenerate')"
		);

		$now = time();

		// Used this month (UTC calendar month).
		$used_this_month = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name
             WHERE action_type IN ('optimize', 'regenerate')
             AND created_at >= %s",
				gmdate( 'Y-m-01 00:00:00', $now )
			)
		);

		// Used today (UTC calendar day).
		$used_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name
             WHERE action_type IN ('optimize', 'regenerate')
             AND created_at >= %s",
				gmdate( 'Y-m-d 00:00:00', $now )
			)
		);

		return array(
			'remaining'       => $remaining_credits,
			'used_total'      => $used_total,
			'used_this_month' => $used_this_month,
			'used_today'      => $used_today,
		);
	}

	/**
	 * Holt History-Statistiken
	 *
	 * @return array History-Statistiken
	 */
	public static function get_history_statistics() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'image_ai_seo_history';

		// Prüfe ob die Tabelle existiert
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name;

		if ( ! $table_exists ) {
			return array(
				'total_actions' => 0,
				'optimizations' => 0,
				'manual_edits'  => 0,
				'reverts'       => 0,
				'restores'      => 0,
				'regenerations' => 0,
			);
		}

		// Aktionen nach Typ
		$actions_by_type = $wpdb->get_results(
			"SELECT action_type, COUNT(*) as count FROM $table_name GROUP BY action_type",
			OBJECT_K
		);

		return array(
			'total_actions' => array_sum( array_column( (array) $actions_by_type, 'count' ) ),
			'optimizations' => isset( $actions_by_type['optimize'] ) ? (int) $actions_by_type['optimize']->count : 0,
			'manual_edits'  => isset( $actions_by_type['manual'] ) ? (int) $actions_by_type['manual']->count : 0,
			'reverts'       => isset( $actions_by_type['revert'] ) ? (int) $actions_by_type['revert']->count : 0,
			'restores'      => isset( $actions_by_type['restore'] ) ? (int) $actions_by_type['restore']->count : 0,
			'regenerations' => isset( $actions_by_type['regenerate'] ) ? (int) $actions_by_type['regenerate']->count : 0,
		);
	}

	/**
	 * Holt die letzten 7 Tage Aktivität für Chart
	 *
	 * The seven buckets are UTC calendar days, today included: the query
	 * starts at 00:00 UTC six days ago and groups by DATE(created_at), which
	 * is the UTC date because `created_at` is stored in UTC. That matches
	 * the gmdate() keys of the zero-filled day list below.
	 *
	 * @return array Aktivität pro Tag
	 */
	public static function get_recent_activity() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'image_ai_seo_history';

		// Prüfe ob die Tabelle existiert
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name;

		if ( ! $table_exists ) {
			return self::get_empty_activity_data();
		}

		// Last seven UTC days, today included.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as count
             FROM $table_name
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
				gmdate( 'Y-m-d 00:00:00', time() - ( 6 * DAY_IN_SECONDS ) )
			),
			OBJECT_K
		);

		// Fülle fehlende Tage mit 0
		$activity = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$date       = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
			$activity[] = array(
				'date'  => $date,
				'label' => date_i18n( 'D', strtotime( $date ) ),
				'count' => isset( $results[ $date ] ) ? (int) $results[ $date ]->count : 0,
			);
		}

		return $activity;
	}

	/**
	 * Gibt leere Aktivitätsdaten zurück
	 *
	 * @return array Leere Aktivitätsdaten
	 */
	private static function get_empty_activity_data() {
		$activity = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$date       = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
			$activity[] = array(
				'date'  => $date,
				'label' => date_i18n( 'D', strtotime( $date ) ),
				'count' => 0,
			);
		}
		return $activity;
	}

	/**
	 * Holt monatliche Statistiken für das letzte Jahr
	 *
	 * Months are UTC calendar months of the UTC `created_at`; the window
	 * starts at 00:00 UTC on the same day twelve months ago.
	 *
	 * @return array Monatliche Statistiken
	 */
	public static function get_monthly_statistics() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'image_ai_seo_history';

		// Prüfe ob die Tabelle existiert
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name;

		if ( ! $table_exists ) {
			return array();
		}

		// Last twelve months (UTC). '%%' is a literal percent sign for prepare().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
                DATE_FORMAT(created_at, '%%Y-%%m') as month,
                COUNT(*) as total,
                SUM(CASE WHEN action_type = 'optimize' THEN 1 ELSE 0 END) as optimizations,
                SUM(CASE WHEN action_type = 'regenerate' THEN 1 ELSE 0 END) as regenerations
             FROM $table_name
             WHERE created_at >= %s
             GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')
             ORDER BY month ASC",
				gmdate( 'Y-m-d 00:00:00', strtotime( '-12 months' ) )
			)
		);

		return $results;
	}
}
