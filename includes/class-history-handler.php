<?php

class Image_AI_SEO_History_Handler {

	const TABLE_NAME = 'image_ai_seo_history';

	/**
	 * Erstellt die Historie-Tabelle bei Plugin-Aktivierung.
	 *
	 * Bewusst ohne "IF NOT EXISTS": dbDelta() erkennt den Tabellennamen ueber
	 * `preg_match( '|CREATE TABLE ([^ ]*)|', ... )` (wp-admin/includes/upgrade.php).
	 * Mit "IF NOT EXISTS" faengt dieses Muster das Wort "IF" als Tabellennamen ab,
	 * dbDelta vergleicht dann nichts und ergaenzt neue Spalten auf bestehenden
	 * Installationen NIE. Genau darauf beruht aber die additive Einfuehrung von
	 * backup_data (D-04). dbDelta legt die Tabelle bei Bedarf ohnehin an und ist
	 * bei wiederholtem Aufruf idempotent.
	 *
	 * `created_at` deliberately has no database default. `DEFAULT
	 * CURRENT_TIMESTAMP` on a DATETIME column is invalid before MySQL 5.6.5,
	 * so the CREATE failed there, and the database clock follows the server's
	 * session time zone instead of UTC. log_change() therefore always writes
	 * the column itself, as UTC from current_time( 'mysql', true ).
	 *
	 * Existing installations keep their column as it is: dbDelta() only
	 * compares the column type (datetime on both sides) and quoted defaults,
	 * it never alters NULL/NOT NULL or drops a CURRENT_TIMESTAMP default, so
	 * the upgrade emits no ALTER for this column and every stored row keeps
	 * its value. Rows written by earlier versions hold the server's session
	 * time rather than UTC, which is UTC on most hosts and otherwise off by
	 * the server offset; they are not converted.
	 *
	 * "PRIMARY KEY  (id)" with two spaces is the format the dbDelta()
	 * documentation asks for.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) NOT NULL,
            action_type varchar(50) NOT NULL,
            old_title varchar(255) DEFAULT '',
            new_title varchar(255) DEFAULT '',
            old_alt_text varchar(255) DEFAULT '',
            new_alt_text varchar(255) DEFAULT '',
            old_filename varchar(255) DEFAULT '',
            new_filename varchar(255) DEFAULT '',
            old_description text,
            new_description text,
            backup_data longtext,
            is_reverted tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY action_type (action_type),
            KEY created_at (created_at)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Loggt eine Bildänderung in der Historie
	 *
	 * @param int    $attachment_id Die Attachment-ID
	 * @param string $action_type Der Typ der Aktion (optimize, manual, revert, regenerate)
	 * @param array  $old_data Die alten Daten
	 * @param array  $new_data Die neuen Daten
	 * @return int|false Die ID des Eintrags oder false bei Fehler
	 */
	public static function log_change( $attachment_id, $action_type, $old_data, $new_data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$result = $wpdb->insert(
			$table_name,
			array(
				'attachment_id'   => $attachment_id,
				'action_type'     => $action_type,
				'old_title'       => isset( $old_data['title'] ) ? $old_data['title'] : '',
				'new_title'       => isset( $new_data['title'] ) ? $new_data['title'] : '',
				'old_alt_text'    => isset( $old_data['alt_text'] ) ? $old_data['alt_text'] : '',
				'new_alt_text'    => isset( $new_data['alt_text'] ) ? $new_data['alt_text'] : '',
				'old_filename'    => isset( $old_data['filename'] ) ? $old_data['filename'] : '',
				'new_filename'    => isset( $new_data['filename'] ) ? $new_data['filename'] : '',
				'old_description' => isset( $old_data['description'] ) ? $old_data['description'] : '',
				'new_description' => isset( $new_data['description'] ) ? $new_data['description'] : '',
				'is_reverted'     => 0,
				// The table has no database default (see create_table()), so
				// the timestamp is always written here, in UTC.
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * D-04: Sichert den vollstaendigen Ist-Zustand aller Datenbankzeilen, die eine
	 * der uebergebenen alten URLs enthalten -- BEVOR irgendetwas geschrieben wird
	 * (D-06: erst pruefen, dann sichern, dann schreiben).
	 *
	 * Der Scan ist bewusst generisch und nicht builder-spezifisch (D-01): er geht
	 * ueber wp_posts.post_content, wp_postmeta.meta_value (ohne meta_key-Filter)
	 * und wp_options.option_value (ohne Transients). Damit werden auch unbekannte
	 * Page Builder und Fremd-Plugins erfasst.
	 *
	 * Die Methode ist frei von Seiteneffekten: sie liest nur und gibt den Snapshot
	 * zurueck. rename_attachment() nutzt ihn doppelt -- fuer den In-Request-Rollback
	 * (D-07) und, ueber den 'snapshot'-Schluessel seines Rueckgabewerts, fuer die
	 * Persistierung am History-Eintrag durch store_backup(), ohne die der vom
	 * Nutzer ausgeloeste Revert nichts wiederherzustellen haette (D-16).
	 *
	 * @param int   $attachment_id Die Attachment-ID (nur fuer Kontext/Logging).
	 * @param array $old_urls Alle alten URLs (Hauptbild + Groessenvarianten).
	 * @return array Liste von array{table:string,row_id:int|string,meta_key:string|null,raw_value:string}
	 */
	public static function backup_full_state( $attachment_id, $old_urls ) {
		global $wpdb;

		$snapshot = array();

		if ( empty( $old_urls ) ) {
			return $snapshot;
		}

		// LIKE-Bedingungen pro Spalte, immer ueber prepare() + esc_like()
		// (projektweiter Standard, siehe class-reference-updater.php) -- T-04-08.
		$post_conditions   = array();
		$meta_conditions   = array();
		$option_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like                = '%' . $wpdb->esc_like( $old_url ) . '%';
			$post_conditions[]   = $wpdb->prepare( 'post_content LIKE %s', $like );
			$meta_conditions[]   = $wpdb->prepare( 'meta_value LIKE %s', $like );
			$option_conditions[] = $wpdb->prepare( 'option_value LIKE %s', $like );
		}

		$posts = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $post_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator.
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE (" . implode( ' OR ', $post_conditions ) . ')'
		);
		foreach ( $posts as $post ) {
			$snapshot[] = array(
				'table'     => 'posts',
				'row_id'    => (int) $post->ID,
				'meta_key'  => null,
				'raw_value' => $post->post_content,
			);
		}

		$meta_rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $meta_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator.
			"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE (" . implode( ' OR ', $meta_conditions ) . ')'
		);
		foreach ( $meta_rows as $meta_row ) {
			$snapshot[] = array(
				'table'     => 'postmeta',
				'row_id'    => (int) $meta_row->post_id,
				'meta_key'  => $meta_row->meta_key,
				// Zusaetzlich zur (post_id, meta_key)-Kombination, weil derselbe
				// Schluessel mehrfach vorkommen darf und restore_from_snapshot()
				// exakt diese eine Zeile treffen muss.
				'meta_id'   => (int) $meta_row->meta_id,
				'raw_value' => $meta_row->meta_value,
			);
		}

		// $option_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the trailing NOT LIKE clauses are fixed literals with no variable interpolation.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			WHERE (" . implode( ' OR ', $option_conditions ) . ")
			AND option_name NOT LIKE '\_transient%'
			AND option_name NOT LIKE '\_site\_transient%'"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $options as $option ) {
			$snapshot[] = array(
				'table'     => 'options',
				'row_id'    => $option->option_name,
				'meta_key'  => null,
				'raw_value' => $option->option_value,
			);
		}

		return $snapshot;
	}

	/**
	 * Persistiert einen Snapshot aus backup_full_state() an einem bestehenden
	 * Historie-Eintrag (Spalte backup_data, JSON-kodiert). Noetig, damit der
	 * Snapshot den aktuellen Request ueberlebt -- also fuer den vom Nutzer
	 * ausgeloesten Revert (D-16).
	 *
	 * Aufgerufen von allen drei Schreibpfaden ueber
	 * Image_AI_SEO_Image_Optimizer::persist_rename_backup(), unmittelbar
	 * nachdem log_change() die ID geliefert hat.
	 *
	 * @param int   $history_id Die Historie-ID.
	 * @param array $snapshot Der Snapshot aus backup_full_state().
	 * @return void
	 */
	public static function store_backup( $history_id, array $snapshot ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->update(
			$table_name,
			array( 'backup_data' => wp_json_encode( $snapshot ) ),
			array( 'id' => $history_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Das wiederverwendbare Restore-Primitiv: schreibt jede im Snapshot erfasste
	 * Zeile Byte fuer Byte zurueck. Bewusst KEIN erneutes str_replace und keine
	 * Rueckwaerts-Ausfuehrung der Umbenennung -- der gesicherte Rohwert gewinnt.
	 *
	 * Zwei Aufrufer:
	 * 1. revert_change() mit einem aus backup_data dekodierten Snapshot (D-16).
	 * 2. Der In-Request-Rollback aus 04-06 mit dem Snapshot, den
	 *    backup_full_state() Sekundenbruchteile vorher zurueckgegeben hat (D-07).
	 * Deshalb public und ohne jede Abhaengigkeit von einem Historie-Eintrag.
	 *
	 * @param array $snapshot Snapshot in der Form, die backup_full_state() liefert.
	 * @return void
	 */
	public static function restore_from_snapshot( array $snapshot ) {
		global $wpdb;

		$touched_post_ids = array();

		foreach ( $snapshot as $row ) {
			if ( ! isset( $row['table'], $row['row_id'], $row['raw_value'] ) ) {
				continue;
			}

			if ( $row['table'] === 'posts' ) {
				$wpdb->update(
					$wpdb->posts,
					array( 'post_content' => $row['raw_value'] ),
					array( 'ID' => (int) $row['row_id'] ),
					array( '%s' ),
					array( '%d' )
				);
				$touched_post_ids[] = (int) $row['row_id'];
			} elseif ( $row['table'] === 'postmeta' && ! empty( $row['meta_key'] ) ) {
				// Bewusst direkter Schreibzugriff statt update_post_meta(): dessen
				// maybe_serialize() serialisiert einen bereits serialisierten String
				// ein zweites Mal und zerstoert damit genau die Byte-Identitaet, um
				// die es hier geht (D-16). Der Rohwert kommt unveraendert zurueck.
				if ( ! empty( $row['meta_id'] ) ) {
					$wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => $row['raw_value'] ),
						array( 'meta_id' => (int) $row['meta_id'] ),
						array( '%s' ),
						array( '%d' )
					);
				} else {
					$wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => $row['raw_value'] ),
						array(
							'post_id'  => (int) $row['row_id'],
							'meta_key' => $row['meta_key'],
						),
						array( '%s' ),
						array( '%d', '%s' )
					);
				}
				wp_cache_delete( (int) $row['row_id'], 'post_meta' );
				$touched_post_ids[] = (int) $row['row_id'];
			} elseif ( $row['table'] === 'options' ) {
				// Die Options-API erwartet den entpackten Wert und serialisiert
				// selbst -- anders als postmeta, wo der Rohstring direkt passt.
				update_option( $row['row_id'], maybe_unserialize( $row['raw_value'] ) );
			}
		}

		// Gezielte Cache-Invalidierung statt globalem Flush (QUAL-05-Muster).
		foreach ( array_unique( $touched_post_ids ) as $touched_post_id ) {
			clean_post_cache( $touched_post_id );
		}
	}

	/**
	 * Holt die Historie eines bestimmten Bildes
	 *
	 * @param int   $attachment_id Die Attachment-ID (optional, 0 für alle)
	 * @param int   $limit Anzahl der Einträge
	 * @param int   $offset Offset für Pagination
	 * @param array $filters Filter-Optionen (search, action_type, status)
	 * @return array Die Historie-Einträge
	 */
	public static function get_history( $attachment_id = 0, $limit = 50, $offset = 0, $filters = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$where_conditions = array();
		$where_values     = array();

		if ( $attachment_id > 0 ) {
			$where_conditions[] = 'h.attachment_id = %d';
			$where_values[]     = $attachment_id;
		}

		// Search filter (filename)
		if ( ! empty( $filters['search'] ) ) {
			$search_term        = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where_conditions[] = '(h.old_filename LIKE %s OR h.new_filename LIKE %s OR h.old_title LIKE %s OR h.new_title LIKE %s)';
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
		}

		// Action type filter
		if ( ! empty( $filters['action_type'] ) ) {
			$where_conditions[] = 'h.action_type = %s';
			$where_values[]     = $filters['action_type'];
		}

		// Status filter (active/reverted)
		if ( ! empty( $filters['status'] ) ) {
			if ( $filters['status'] === 'reverted' ) {
				$where_conditions[] = 'h.is_reverted = 1';
			} elseif ( $filters['status'] === 'active' ) {
				$where_conditions[] = 'h.is_reverted = 0';
			}
		}

		$where = '';
		if ( ! empty( $where_conditions ) ) {
			$where = ' WHERE ' . implode( ' AND ', $where_conditions );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is always $wpdb->prefix . self::TABLE_NAME (never user input; table names cannot be prepare() placeholders); $where contains only fixed condition text with %s/%d placeholders whose actual values are supplied to $wpdb->prepare() below via $where_values.
		$sql = "SELECT h.*, p.post_title as current_title, p.guid as image_url
                FROM $table_name h
                LEFT JOIN {$wpdb->posts} p ON h.attachment_id = p.ID
                $where
                ORDER BY h.created_at DESC
                LIMIT %d OFFSET %d";

		$where_values[] = $limit;
		$where_values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is passed through $wpdb->prepare() with $where_values on this very line; the sniff cannot see through the two-step assignment above.
		return $wpdb->get_results( $wpdb->prepare( $sql, $where_values ) );
	}

	/**
	 * Holt die Gesamtanzahl der Historie-Einträge
	 *
	 * @param array $filters Filter-Optionen (search, action_type, status)
	 * @return int Die Anzahl der Einträge
	 */
	public static function get_history_count( $filters = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Support for old API (attachment_id as first param)
		if ( is_numeric( $filters ) ) {
			$attachment_id = (int) $filters;
			if ( $attachment_id > 0 ) {
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $table_name WHERE attachment_id = %d",
						$attachment_id
					)
				);
			}
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		}

		$where_conditions = array();
		$where_values     = array();

		// Search filter (filename)
		if ( ! empty( $filters['search'] ) ) {
			$search_term        = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where_conditions[] = '(old_filename LIKE %s OR new_filename LIKE %s OR old_title LIKE %s OR new_title LIKE %s)';
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
		}

		// Action type filter
		if ( ! empty( $filters['action_type'] ) ) {
			$where_conditions[] = 'action_type = %s';
			$where_values[]     = $filters['action_type'];
		}

		// Status filter (active/reverted)
		if ( ! empty( $filters['status'] ) ) {
			if ( $filters['status'] === 'reverted' ) {
				$where_conditions[] = 'is_reverted = 1';
			} elseif ( $filters['status'] === 'active' ) {
				$where_conditions[] = 'is_reverted = 0';
			}
		}

		$where = '';
		if ( ! empty( $where_conditions ) ) {
			$where = ' WHERE ' . implode( ' AND ', $where_conditions );
		}

		if ( empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is always $wpdb->prefix . self::TABLE_NAME (never user input); $where_values is empty here, so $where (if present) is only a fixed literal condition (e.g. 'is_reverted = 1') with no user-supplied placeholders to prepare.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" . $where );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name" . $where, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is always $wpdb->prefix . self::TABLE_NAME (never user input); $where contains only fixed condition text with %s placeholders whose actual values are supplied via $where_values below.
				$where_values
			)
		);
	}

	/**
	 * One-time repair for installations that ran a version before 2.1.1: an
	 * undo there left the "optimized" mark in place, so a reverted image kept
	 * showing "Optimized" and was skipped by every bulk run. Clears the mark on
	 * every image whose history entries (apart from revert records) are all
	 * reverted, i.e. whose current data is the original again. Images without
	 * any history are never touched.
	 *
	 * @return int Number of images whose mark was cleared.
	 */
	public static function repair_reverted_optimized_marks() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is $wpdb->prefix . self::TABLE_NAME, never user input; one-time migration read.
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT h.attachment_id
				FROM $table_name h
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = h.attachment_id AND pm.meta_key = %s
				WHERE h.action_type <> %s
				GROUP BY h.attachment_id
				HAVING SUM( CASE WHEN h.is_reverted = 0 THEN 1 ELSE 0 END ) = 0",
				Image_AI_SEO_Metadata_Handler::OPTIMIZATION_META_KEY,
				'revert'
			)
		);
		// phpcs:enable

		foreach ( $attachment_ids as $attachment_id ) {
			Image_AI_SEO_Metadata_Handler::unmark_image_as_optimized( (int) $attachment_id );
		}

		return count( $attachment_ids );
	}

	/**
	 * Whether an older change to the attachment is still in effect, i.e. a
	 * history entry created before $history_id that is neither a revert record
	 * nor reverted itself. Decides whether the image stays "optimized" after
	 * $history_id is reverted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $history_id    The entry being reverted.
	 * @return bool
	 */
	public static function has_active_change_before( $attachment_id, $history_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is $wpdb->prefix . self::TABLE_NAME, never user input; a fresh read is required right after the revert.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE attachment_id = %d AND id < %d AND is_reverted = 0 AND action_type <> %s",
				$attachment_id,
				$history_id,
				'revert'
			)
		);
		// phpcs:enable

		return $count > 0;
	}

	/**
	 * Holt einen einzelnen Historie-Eintrag
	 *
	 * @param int $history_id Die Historie-ID
	 * @return object|null Der Historie-Eintrag
	 */
	public static function get_history_entry( $history_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d",
				$history_id
			)
		);
	}

	/**
	 * Setzt eine Änderung zurück (Revert)
	 *
	 * @param int $history_id Die Historie-ID
	 * @return array Status-Array mit success und message
	 */
	public static function revert_change( $history_id ) {
		global $wpdb;

		$entry = self::get_history_entry( $history_id );

		if ( ! $entry ) {
			return array(
				'success' => false,
				'message' => __( 'History entry not found.', 'altimo' ),
			);
		}

		// Prüfe ob bereits zurückgesetzt
		if ( $entry->is_reverted ) {
			return array(
				'success' => false,
				'message' => __( 'This change has already been reverted.', 'altimo' ),
			);
		}

		$attachment_id = $entry->attachment_id;

		// Prüfe ob das Bild noch existiert
		if ( ! get_post( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'The image no longer exists.', 'altimo' ),
			);
		}

		// Hole die aktuellen Daten vor dem Revert
		$current_data = Image_AI_SEO_Metadata_Handler::get_image_metadata( $attachment_id );

		// Setze auf die alten Werte zurück
		Image_AI_SEO_Metadata_Handler::update_image_metadata(
			$attachment_id,
			$entry->old_title,
			$entry->old_alt_text,
			$entry->old_description
		);

		$rename_notice = '';

		// Benenne die Datei zurück (wenn der Dateiname geändert wurde)
		if ( ! empty( $entry->old_filename ) && $entry->old_filename !== $entry->new_filename ) {
			$old_file             = get_attached_file( $attachment_id );
			$filename_without_ext = pathinfo( $entry->old_filename, PATHINFO_FILENAME );
			$rename_result        = Image_AI_SEO_File_Handler::rename_attachment( $attachment_id, $filename_without_ext, $old_file );

			if ( is_wp_error( $rename_result ) ) {
				if ( 'offload_detected' === $rename_result->get_error_code() ) {
					// D-13: Der Dateiname eines ausgelagerten Bildes wurde nie
					// geändert, also gibt es auch nichts zurückzubenennen. Der
					// Revert der Metadaten bleibt gültig, der Grund wird an die
					// Meldung angehängt (T-04-15).
					$rename_notice = ' ' . $rename_result->get_error_message();
				} else {
					// Echter Fehlschlag -- Metadaten zurücksetzen wie bisher.
					Image_AI_SEO_Metadata_Handler::update_image_metadata(
						$attachment_id,
						$current_data['title'],
						$current_data['alt_text'],
						$current_data['description']
					);
					Image_AI_SEO_Plugin::log(
						sprintf(
							'Altimo: Failed to rename file during revert for attachment %d (from %s to %s): %s',
							$attachment_id,
							$entry->new_filename,
							$entry->old_filename,
							$rename_result->get_error_message()
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Failed to rename file. Metadata changes have been rolled back.', 'altimo' ),
					);
				}
			}
		}

		// D-16: Referenzen aus der in D-04 gesicherten Fassung wiederherstellen --
		// vor dem Markieren als zurueckgesetzt, damit ein Fehlschlag den Eintrag
		// unmarkiert laesst (dieselbe Fail-Early-Form wie oben beim Dateinamen).
		// Leeres backup_data heisst: Alt-Eintrag von vor D-04 oder reine
		// Metadaten-Aenderung -- dann bleibt es beim bisherigen Verhalten.
		if ( ! empty( $entry->backup_data ) ) {
			$decoded_snapshot = json_decode( $entry->backup_data, true );

			if ( ! is_array( $decoded_snapshot ) ) {
				Image_AI_SEO_Plugin::log(
					sprintf(
						'Altimo: Corrupted backup_data on history entry %d, skipping reference restore',
						$history_id
					)
				);
				return array(
					'success' => false,
					'message' => __( 'The stored backup could not be read. References were not restored.', 'altimo' ),
				);
			}

			self::restore_from_snapshot( $decoded_snapshot );
		}

		// Markiere den Eintrag als zurückgesetzt
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->update(
			$table_name,
			array( 'is_reverted' => 1 ),
			array( 'id' => $history_id ),
			array( '%d' ),
			array( '%d' )
		);

		// update_image_metadata() above always marks the image as optimized. If
		// no older change is still in effect, the revert took the image back to
		// its original, unoptimized state: clear the mark so the media column,
		// the bulk counters and the next bulk run treat it as unoptimized again.
		if ( ! self::has_active_change_before( $attachment_id, $history_id ) ) {
			Image_AI_SEO_Metadata_Handler::unmark_image_as_optimized( $attachment_id );
		}

		// Logge die Revert-Aktion
		self::log_change(
			$attachment_id,
			'revert',
			array(
				'title'       => $current_data['title'],
				'alt_text'    => $current_data['alt_text'],
				'filename'    => $current_data['filename'],
				'description' => $current_data['description'],
			),
			array(
				'title'       => $entry->old_title,
				'alt_text'    => $entry->old_alt_text,
				'filename'    => $entry->old_filename,
				'description' => $entry->old_description,
			)
		);

		return array(
			'success' => true,
			'message' => __( 'Changes successfully reverted.', 'altimo' ) . $rename_notice,
		);
	}

	/**
	 * Stellt die AI-Änderungen wieder her (Restore)
	 *
	 * @param int $history_id Die Historie-ID
	 * @return array Status-Array mit success und message
	 */
	public static function restore_change( $history_id ) {
		global $wpdb;

		$entry = self::get_history_entry( $history_id );

		if ( ! $entry ) {
			return array(
				'success' => false,
				'message' => __( 'History entry not found.', 'altimo' ),
			);
		}

		// Nur zurückgesetzte Einträge können wiederhergestellt werden
		if ( ! $entry->is_reverted ) {
			return array(
				'success' => false,
				'message' => __( 'This change has not been reverted and cannot be restored.', 'altimo' ),
			);
		}

		$attachment_id = $entry->attachment_id;

		// Prüfe ob das Bild noch existiert
		if ( ! get_post( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'The image no longer exists.', 'altimo' ),
			);
		}

		// Hole die aktuellen Daten vor dem Restore
		$current_data = Image_AI_SEO_Metadata_Handler::get_image_metadata( $attachment_id );

		// Stelle die neuen (AI-optimierten) Werte wieder her
		Image_AI_SEO_Metadata_Handler::update_image_metadata(
			$attachment_id,
			$entry->new_title,
			$entry->new_alt_text,
			$entry->new_description
		);

		$rename_notice = '';

		// Benenne die Datei wieder um (wenn der Dateiname geändert wurde)
		if ( ! empty( $entry->new_filename ) && $entry->old_filename !== $entry->new_filename ) {
			$old_file             = get_attached_file( $attachment_id );
			$filename_without_ext = pathinfo( $entry->new_filename, PATHINFO_FILENAME );
			$rename_result        = Image_AI_SEO_File_Handler::rename_attachment( $attachment_id, $filename_without_ext, $old_file );

			if ( is_wp_error( $rename_result ) ) {
				if ( 'offload_detected' === $rename_result->get_error_code() ) {
					// D-13: siehe revert_change() -- der Dateiname bleibt
					// unangetastet, die Metadaten-Wiederherstellung gilt trotzdem.
					$rename_notice = ' ' . $rename_result->get_error_message();
				} else {
					// Echter Fehlschlag -- Metadaten zurücksetzen wie bisher.
					Image_AI_SEO_Metadata_Handler::update_image_metadata(
						$attachment_id,
						$current_data['title'],
						$current_data['alt_text'],
						$current_data['description']
					);
					Image_AI_SEO_Plugin::log(
						sprintf(
							'Altimo: Failed to rename file during restore for attachment %d (from %s to %s): %s',
							$attachment_id,
							$entry->old_filename,
							$entry->new_filename,
							$rename_result->get_error_message()
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Failed to rename file. Metadata changes have been rolled back.', 'altimo' ),
					);
				}
			}
		}

		// Markiere den Eintrag als nicht mehr zurückgesetzt
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->update(
			$table_name,
			array( 'is_reverted' => 0 ),
			array( 'id' => $history_id ),
			array( '%d' ),
			array( '%d' )
		);

		// Logge die Restore-Aktion
		self::log_change(
			$attachment_id,
			'restore',
			array(
				'title'       => $current_data['title'],
				'alt_text'    => $current_data['alt_text'],
				'filename'    => $current_data['filename'],
				'description' => $current_data['description'],
			),
			array(
				'title'       => $entry->new_title,
				'alt_text'    => $entry->new_alt_text,
				'filename'    => $entry->new_filename,
				'description' => $entry->new_description,
			)
		);

		return array(
			'success' => true,
			'message' => __( 'Changes successfully restored.', 'altimo' ) . $rename_notice,
		);
	}

	/**
	 * Generiert ein Bild neu über die AI
	 *
	 * @param int $attachment_id Die Attachment-ID
	 * @return array Status-Array mit success und message
	 */
	public static function regenerate_image( $attachment_id ) {
		// Prüfe ob das Bild existiert
		if ( ! get_post( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'The image does not exist or is not a valid image.', 'altimo' ),
			);
		}

		// Hole die aktuellen Daten vor der Neugenerierung
		$current_data = Image_AI_SEO_Metadata_Handler::get_image_metadata( $attachment_id );

		// Optimiere das Bild neu (reiner API-Call, keine Schreibvorgänge)
		$result = Image_AI_SEO_Image_Optimizer::generate( $attachment_id );

		if ( ! $result['success'] ) {
			return $result;
		}

		// Schreibt Metadaten, benennt um (mit Rollback bei Fehlschlag) und loggt die
		// History -- geteilter Vertrag mit optimize_single_image() (QUAL-04-Split,
		// RESEARCH.md Pitfall 4). Der beobachtbare Fehlertext bleibt identisch
		// ('Failed to rename file. Changes have been rolled back.').
		$apply_result = Image_AI_SEO_Image_Optimizer::apply( $attachment_id, $result['data'], $current_data, 'regenerate' );

		if ( ! $apply_result['success'] ) {
			return $apply_result;
		}

		return array(
			'success' => true,
			'message' => __( 'Image successfully regenerated.', 'altimo' ),
			'data'    => $apply_result['data'],
		);
	}

	/**
	 * Registriert den täglichen Cron-Hook für die automatische
	 * History-Bereinigung (QUAL-07). Idempotent: ein wiederholter Aufruf
	 * plant kein zweites Event (wp_next_scheduled()-Guard, analog zu
	 * schedule_if_needed() aus Phase 2).
	 *
	 * Wird ausschließlich über Image_AI_SEO_Plugin::boot() verdrahtet, dem
	 * einzigen Ort, an dem Klasse::init()-Aufrufe stattfinden.
	 */
	public static function init() {
		add_action( 'image_ai_seo_history_cleanup_cron', array( __CLASS__, 'run_scheduled_cleanup' ) );

		if ( ! wp_next_scheduled( 'image_ai_seo_history_cleanup_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'image_ai_seo_history_cleanup_cron' );
		}
	}

	/**
	 * Cron-Callback für den täglichen Cleanup-Hook. Ruft cleanup_old_entries()
	 * unverändert auf; existiert nur, damit der Action-Callback selbst
	 * void zurückgibt (PHPStan `return.void`), statt den Rückgabewert von
	 * cleanup_old_entries() (int, für den direkten Aufrufer relevant) über
	 * den Hook nach außen zu geben.
	 */
	public static function run_scheduled_cleanup() {
		self::cleanup_old_entries();
	}

	/**
	 * Löscht alte Historie-Einträge
	 *
	 * The cut-off is computed in PHP as UTC, the same clock log_change()
	 * writes `created_at` with, instead of the database's NOW(), which
	 * follows the server's session time zone.
	 *
	 * @param int $days Einträge älter als X Tage löschen
	 * @return int Anzahl der gelöschten Einträge (0 bei Fehler)
	 */
	public static function cleanup_old_entries( $days = 90 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE created_at < %s",
				$cutoff
			)
		);

		if ( $result === false ) {
			Image_AI_SEO_Plugin::log( 'Altimo: Failed to cleanup old history entries' );
			return 0;
		}

		return $result;
	}

	/**
	 * Holt das Thumbnail eines Bildes
	 *
	 * @param int $attachment_id Die Attachment-ID
	 * @return string Die Thumbnail-URL oder leerer String
	 */
	public static function get_thumbnail_url( $attachment_id ) {
		$thumbnail = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		return $thumbnail ? $thumbnail[0] : '';
	}
}
