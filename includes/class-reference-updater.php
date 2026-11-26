<?php

defined( 'ABSPATH' ) || exit;

class Image_AI_SEO_Reference_Updater {

	/**
	 * Datenbankfehler, die WAEHREND EIGENER Schreibvorgaenge aufgetreten sind.
	 *
	 * Frueher beobachtete class-file-handler.php stattdessen den globalen
	 * Query-Strom ueber einen `query`-Filter. Der sah aber auch jede Abfrage
	 * von Fremdcode, der sich in update_option, save_post, updated_post_meta
	 * oder einen Object-Cache einhaengt -- ein voellig unbeteiligter Fehler
	 * rollte damit eine tatsaechlich gelungene Umbenennung samt vollem
	 * Referenz-Restore zurueck. Beobachtet wird jetzt ausschliesslich das,
	 * was diese Klasse selbst schreibt.
	 *
	 * @var string[]
	 */
	private static $db_errors = array();

	/**
	 * Fuehrt ein $wpdb->update() aus und merkt sich einen dabei aufgetretenen
	 * Datenbankfehler.
	 *
	 * @param string $table Zieltabelle.
	 * @param array  $data  Zu schreibende Spalten.
	 * @param array  $where WHERE-Bedingung.
	 * @return int|false Rueckgabe von $wpdb->update().
	 */
	private static function guarded_update( $table, array $data, array $where ) {
		global $wpdb;

		$result = $wpdb->update( $table, $data, $where );

		if ( false === $result ) {
			self::$db_errors[] = '' !== (string) $wpdb->last_error
				? $wpdb->last_error
				: sprintf( 'update on %s failed without an error message', $table );
		}

		return $result;
	}

	/**
	 * Aktualisiert alle Verweise auf ein Bild in der gesamten Website
	 *
	 * @param int    $attachment_id Die ID des Bildes
	 * @param array  $old_urls Array mit allen alten URLs (Hauptbild + Größenvarianten)
	 * @param array  $new_urls Array mit allen neuen URLs (Hauptbild + Größenvarianten)
	 * @param string $new_alt_text Der neue Alt-Text (optional)
	 * @return array Statistik über die aktualisierten Referenzen
	 */
	public static function update_image_references( $attachment_id, $old_urls, $new_urls, $new_alt_text = '' ) {
		global $wpdb;

		// Statistiken initialisieren
		self::$db_errors = array();

		$stats = array(
			'posts_updated'    => 0,
			'postmeta_updated' => 0,
			'options_updated'  => 0,
			'widgets_updated'  => 0,
			'menus_updated'    => 0,
			// Leer heisst: kein einziger eigener Schreibvorgang ist
			// gescheitert. Der Aufrufer entscheidet daraufhin ueber den
			// Rollback (D-07).
			'db_errors'        => array(),
		);

		// Sicherheitsprüfung: Arrays müssen gleiche Länge haben
		if ( count( $old_urls ) !== count( $new_urls ) || empty( $old_urls ) ) {
			return $stats;
		}

		// Erstelle ein URL-Mapping für effiziente Ersetzung
		$url_mapping = array_combine( $old_urls, $new_urls );

		// Sammelt die IDs aller Posts, deren Postmeta (Page-Builder-Daten) tatsächlich
		// geändert wurde, damit deren Post-Cache gezielt invalidiert werden kann
		// (QUAL-05, ersetzt den globalen wp_cache_flush()).
		$touched_post_ids = array();

		// Update in Beiträgen, Seiten und allen Custom Post Types
		$stats['posts_updated'] = self::update_post_content( $url_mapping, $new_alt_text, $old_urls );

		// Update in verschiedenen Page Buildern
		$stats['postmeta_updated'] += self::update_elementor_content( $url_mapping, $new_alt_text, $old_urls, $touched_post_ids );
		$stats['postmeta_updated'] += self::update_divi_content( $url_mapping, $new_alt_text, $old_urls, $touched_post_ids );
		$stats['postmeta_updated'] += self::update_wpbakery_content( $url_mapping, $new_alt_text, $old_urls, $touched_post_ids );
		$stats['postmeta_updated'] += self::update_beaver_content( $url_mapping, $new_alt_text, $old_urls, $touched_post_ids );
		$stats['postmeta_updated'] += self::update_oxygen_content( $url_mapping, $new_alt_text, $old_urls, $touched_post_ids );
		$stats['postmeta_updated'] += self::update_thrive_content( $url_mapping, $new_alt_text, $old_urls, $touched_post_ids );
		$stats['postmeta_updated'] += self::update_bricks_content( $url_mapping, $new_alt_text, $old_urls, $touched_post_ids );

		// Update in Widgets
		$stats['widgets_updated'] = self::update_widget_content( $url_mapping, $new_alt_text );

		// Update in Menüs
		$stats['menus_updated'] = self::update_menu_items( $url_mapping, $new_alt_text );

		// Update in WordPress Optionen
		$stats['options_updated'] = self::update_options( $url_mapping, $old_urls );

		// Gezielte Cache-Invalidierung statt globalem wp_cache_flush() (QUAL-05):
		// update_post_content() invalidiert den Post-Cache bereits pro geändertem Post
		// (siehe dort), hier zusätzlich für alle Page-Builder-Postmeta-Änderungen sowie
		// den Attachment-Cache des optimierten Bildes selbst.
		foreach ( array_unique( $touched_post_ids ) as $touched_post_id ) {
			clean_post_cache( $touched_post_id );
		}
		clean_attachment_cache( $attachment_id );

		$stats['db_errors'] = self::$db_errors;
		self::$db_errors    = array();

		return $stats;
	}

	/**
	 * Aktualisiert Bildverweise in allen Post Types (Beiträge, Seiten, CPTs)
	 */
	private static function update_post_content( $url_mapping, $new_alt_text, $old_urls ) {
		global $wpdb;

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen für alle alten URLs
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// Hole alle Posts (alle Post-Types) die eine der alten URLs enthalten
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$sql = "SELECT ID, post_content FROM {$wpdb->posts}
                WHERE post_status != 'trash'
                AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment')
                AND (" . implode( ' OR ', $like_conditions ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled entirely from fixed literals and already-prepared fragments above (see comment there), never raw user input.
		$posts = $wpdb->get_results( $sql );

		foreach ( $posts as $post ) {
			$content         = $post->post_content;
			$updated_content = $content;

			// Ersetze alle alten URLs durch neue URLs
			foreach ( $url_mapping as $old_url => $new_url ) {
				$updated_content = str_replace( $old_url, $new_url, $updated_content );
			}

			// Update Alt-Text nur für das betreffende Bild
			if ( ! empty( $new_alt_text ) ) {
				$updated_content = self::update_alt_text_in_content( $updated_content, $url_mapping, $new_alt_text );
			}

			// Speichere nur, wenn sich etwas geändert hat
			if ( $updated_content !== $content ) {
				self::guarded_update(
					$wpdb->posts,
					array( 'post_content' => $updated_content ),
					array( 'ID' => $post->ID )
				);
				++$updated_count;

				// Leere den Post-Cache
				clean_post_cache( $post->ID );
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert den Alt-Text nur für Bilder, die zu den geänderten URLs gehören
	 */
	private static function update_alt_text_in_content( $content, $url_mapping, $new_alt_text ) {
		$new_urls = array_values( $url_mapping );

		// Für HTML img-Tags: Suche nach img-Tags mit einer der neuen URLs und aktualisiere das alt-Attribut
		foreach ( $new_urls as $new_url ) {
			// Escape für Regex
			$escaped_url = preg_quote( $new_url, '/' );

			// Pattern für img-Tags mit dieser URL
			$pattern     = '/(<img[^>]*src=["\']' . $escaped_url . '["\'][^>]*?)alt=["\'][^"\']*["\']([^>]*>)/i';
			$replacement = '$1alt="' . esc_attr( $new_alt_text ) . '"$2';
			$content     = preg_replace( $pattern, $replacement, $content );

			// Auch umgekehrte Reihenfolge (alt vor src)
			$pattern     = '/(<img[^>]*?)alt=["\'][^"\']*["\']([^>]*src=["\']' . $escaped_url . '["\'][^>]*>)/i';
			$replacement = '$1alt="' . esc_attr( $new_alt_text ) . '"$2';
			$content     = preg_replace( $pattern, $replacement, $content );
		}

		// Für Gutenberg-Blöcke: Nur wenn die URL im selben Block vorkommt
		foreach ( $new_urls as $new_url ) {
			$escaped_url = preg_quote( $new_url, '/' );

			// Pattern für JSON-Objekte mit url und alt
			$pattern     = '/("url"\s*:\s*"' . $escaped_url . '"[^}]*?"alt"\s*:\s*")[^"]*(")/i';
			$replacement = '${1}' . esc_attr( $new_alt_text ) . '${2}';
			$content     = preg_replace( $pattern, $replacement, $content );

			// Umgekehrte Reihenfolge (alt vor url)
			$pattern     = '/("alt"\s*:\s*")[^"]*("[^}]*?"url"\s*:\s*"' . $escaped_url . '")/i';
			$replacement = '${1}' . esc_attr( $new_alt_text ) . '${2}';
			$content     = preg_replace( $pattern, $replacement, $content );
		}

		return $content;
	}

	/**
	 * Ersetzt URLs in einem rohen Postmeta-Wert serialisierungssicher (D-01).
	 *
	 * Die eigentliche Ersetzung übernimmt ausschließlich
	 * Image_AI_SEO_Serialization_Safe_Replacer -- es gibt bewusst keine
	 * builder-spezifische Ersetzungslogik mehr.
	 *
	 * @param string $content      Rohwert genau wie in der Datenbank gespeichert.
	 * @param array  $url_mapping  Map alte URL => neue URL.
	 * @param string $new_alt_text Neuer Alt-Text (optional).
	 * @return array{0:string,1:bool} [ neuer Rohwert, ob er sich geändert hat ].
	 */
	private static function replace_in_builder_value( $content, $url_mapping, $new_alt_text ) {
		list( $updated_content, $changed ) = Image_AI_SEO_Serialization_Safe_Replacer::replace( $content, $url_mapping );

		// Der Alt-Text wird per Regex auf Textebene ersetzt. Auf einem
		// serialisierten String würde das die s:N:-Längenpräfixe erneut
		// zerstören -- exakt der Defekt, den diese Phase behebt (D-01).
		// Deshalb greift dieser Schritt nur bei nicht serialisierten Werten.
		if ( ! empty( $new_alt_text ) && ! is_serialized( $content ) ) {
			$with_alt_text = self::update_alt_text_in_content( $updated_content, $url_mapping, $new_alt_text );

			if ( $with_alt_text !== $updated_content ) {
				$updated_content = $with_alt_text;
				$changed         = true;
			}
		}

		return array( $updated_content, $changed );
	}

	/**
	 * Schreibt einen Postmeta-Rohwert unverändert zurück.
	 *
	 * Bewusst NICHT über update_post_meta(): dessen maybe_serialize()
	 * serialisiert einen bereits serialisierten String ein weiteres Mal und
	 * legt damit bei jeder Umbenennung eine zusätzliche Serialisierungsebene
	 * an. Der Rohwert wird deshalb direkt in die Zeile geschrieben, die er
	 * auch gelesen wurde (meta_id), und der Meta-Cache gezielt geleert.
	 *
	 * @param int    $meta_id   Die meta_id der gelesenen Zeile.
	 * @param int    $post_id   Post-ID für die Cache-Invalidierung.
	 * @param string $raw_value Der zu schreibende Rohwert.
	 * @return void
	 */
	private static function write_raw_postmeta( $meta_id, $post_id, $raw_value ) {
		global $wpdb;

		self::guarded_update(
			$wpdb->postmeta,
			array( 'meta_value' => $raw_value ),
			array( 'meta_id' => $meta_id )
		);

		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Aktualisiert Bildverweise in Elementor
	 */
	private static function update_elementor_content( $url_mapping, $new_alt_text, $old_urls, array &$touched_post_ids ) {
		global $wpdb;

		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return 0;
		}

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$elementor_data = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_elementor_data'
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $elementor_data as $data ) {
			$content = $data->meta_value;

			// D-02: Elementor speichert JSON. Der Wert wird dekodiert, im
			// Datenbaum typsicher ersetzt und wieder kodiert -- nie per
			// str_replace auf dem Rohstring.
			$decoded = json_decode( $content, true );

			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				// D-03: bereits beschädigte Bestandsdaten werden gemeldet,
				// nicht repariert und keinesfalls überschrieben.
				Image_AI_SEO_Plugin::log(
					sprintf(
						'Altimo: _elementor_data of post %1$d is not valid JSON (%2$s) -- skipped, not modified.',
						(int) $data->post_id,
						json_last_error_msg()
					)
				);
				continue;
			}

			$replaced_tree   = Image_AI_SEO_Serialization_Safe_Replacer::replace_decoded_tree( $decoded, $url_mapping );
			$updated_content = wp_json_encode( $replaced_tree );

			if ( false === $updated_content ) {
				continue;
			}

			// Der Alt-Text darf hier auf Textebene ersetzt werden: JSON kennt
			// keine Längenpräfixe, eine Längenänderung ist unkritisch.
			if ( ! empty( $new_alt_text ) ) {
				$updated_content = self::update_alt_text_in_content( $updated_content, $url_mapping, $new_alt_text );
			}

			if ( $updated_content !== $content ) {
				self::write_raw_postmeta( $data->meta_id, $data->post_id, $updated_content );
				++$updated_count;
				$touched_post_ids[] = $data->post_id;
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in Divi
	 */
	private static function update_divi_content( $url_mapping, $new_alt_text, $old_urls, array &$touched_post_ids ) {
		global $wpdb;

		if ( ! function_exists( 'et_get_option' ) ) {
			return 0;
		}

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$divi_data = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key LIKE '_et_pb_%'
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $divi_data as $data ) {
			list( $updated_content, $changed ) = self::replace_in_builder_value( $data->meta_value, $url_mapping, $new_alt_text );

			if ( $changed ) {
				self::write_raw_postmeta( $data->meta_id, $data->post_id, $updated_content );
				++$updated_count;
				$touched_post_ids[] = $data->post_id;
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in WPBakery Page Builder
	 */
	private static function update_wpbakery_content( $url_mapping, $new_alt_text, $old_urls, array &$touched_post_ids ) {
		global $wpdb;

		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			return 0;
		}

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// Korrigierte SQL-Abfrage mit richtiger Klammerung
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$wpbakery_data = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
            WHERE (meta_key = '_wpb_shortcodes_custom_css' OR meta_key = '_wpb_post_custom_css')
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $wpbakery_data as $data ) {
			list( $updated_content, $changed ) = self::replace_in_builder_value( $data->meta_value, $url_mapping, $new_alt_text );

			if ( $changed ) {
				self::write_raw_postmeta( $data->meta_id, $data->post_id, $updated_content );
				++$updated_count;
				$touched_post_ids[] = $data->post_id;
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in Beaver Builder
	 */
	private static function update_beaver_content( $url_mapping, $new_alt_text, $old_urls, array &$touched_post_ids ) {
		global $wpdb;

		if ( ! class_exists( 'FLBuilder' ) ) {
			return 0;
		}

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$beaver_data = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_fl_builder_data'
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $beaver_data as $data ) {
			list( $updated_content, $changed ) = self::replace_in_builder_value( $data->meta_value, $url_mapping, $new_alt_text );

			if ( $changed ) {
				self::write_raw_postmeta( $data->meta_id, $data->post_id, $updated_content );
				++$updated_count;
				$touched_post_ids[] = $data->post_id;
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in Oxygen Builder
	 */
	private static function update_oxygen_content( $url_mapping, $new_alt_text, $old_urls, array &$touched_post_ids ) {
		global $wpdb;

		if ( ! defined( 'CT_VERSION' ) ) {
			return 0;
		}

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$oxygen_data = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = 'ct_builder_shortcodes'
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $oxygen_data as $data ) {
			list( $updated_content, $changed ) = self::replace_in_builder_value( $data->meta_value, $url_mapping, $new_alt_text );

			if ( $changed ) {
				self::write_raw_postmeta( $data->meta_id, $data->post_id, $updated_content );
				++$updated_count;
				$touched_post_ids[] = $data->post_id;
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in Thrive Architect
	 */
	private static function update_thrive_content( $url_mapping, $new_alt_text, $old_urls, array &$touched_post_ids ) {
		global $wpdb;

		if ( ! defined( 'TVE_IN_ARCHITECT' ) && ! class_exists( 'TCB_Editor' ) ) {
			return 0;
		}

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$thrive_data = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = 'tve_updated_post'
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $thrive_data as $data ) {
			list( $updated_content, $changed ) = self::replace_in_builder_value( $data->meta_value, $url_mapping, $new_alt_text );

			if ( $changed ) {
				self::write_raw_postmeta( $data->meta_id, $data->post_id, $updated_content );
				++$updated_count;
				$touched_post_ids[] = $data->post_id;
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in Bricks Builder
	 */
	private static function update_bricks_content( $url_mapping, $new_alt_text, $old_urls, array &$touched_post_ids ) {
		global $wpdb;

		if ( ! defined( 'BRICKS_VERSION' ) ) {
			return 0;
		}

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$bricks_data = $wpdb->get_results(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_bricks_page_content'
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $bricks_data as $data ) {
			list( $updated_content, $changed ) = self::replace_in_builder_value( $data->meta_value, $url_mapping, $new_alt_text );

			if ( $changed ) {
				self::write_raw_postmeta( $data->meta_id, $data->post_id, $updated_content );
				++$updated_count;
				$touched_post_ids[] = $data->post_id;
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in Widgets
	 */
	private static function update_widget_content( $url_mapping, $new_alt_text ) {
		$updated_count = 0;

		// Media Image Widget
		$widgets = get_option( 'widget_media_image' );

		if ( ! empty( $widgets ) && is_array( $widgets ) ) {
			$updated = false;

			foreach ( $widgets as $key => $widget ) {
				if ( ! is_array( $widget ) ) {
					continue;
				}

				if ( ! empty( $widget['url'] ) ) {
					foreach ( $url_mapping as $old_url => $new_url ) {
						if ( $widget['url'] === $old_url ) {
							$widgets[ $key ]['url'] = $new_url;

							if ( ! empty( $new_alt_text ) ) {
								$widgets[ $key ]['alt'] = $new_alt_text;
							}

							$updated = true;
							break;
						}
					}
				}

				// Prüfe auch attachment_id falls vorhanden
				if ( ! empty( $widget['attachment_id'] ) ) {
					// Widget verwendet attachment_id, aktualisiere auch das
					foreach ( $url_mapping as $old_url => $new_url ) {
						if ( strpos( $widget['url'] ?? '', basename( $old_url ) ) !== false ) {
							$widgets[ $key ]['url'] = $new_url;

							if ( ! empty( $new_alt_text ) ) {
								$widgets[ $key ]['alt'] = $new_alt_text;
							}

							$updated = true;
							break;
						}
					}
				}
			}

			if ( $updated ) {
				update_option( 'widget_media_image', $widgets );
				++$updated_count;
			}
		}

		// Text Widget und Custom HTML Widget
		$text_widgets = array( 'widget_text', 'widget_custom_html' );

		foreach ( $text_widgets as $widget_option ) {
			$widgets = get_option( $widget_option );

			if ( ! empty( $widgets ) && is_array( $widgets ) ) {
				$updated = false;

				foreach ( $widgets as $key => $widget ) {
					if ( ! is_array( $widget ) || empty( $widget['content'] ) ) {
						continue;
					}

					$content         = $widget['content'];
					$updated_content = $content;

					foreach ( $url_mapping as $old_url => $new_url ) {
						$updated_content = str_replace( $old_url, $new_url, $updated_content );
					}

					if ( ! empty( $new_alt_text ) ) {
						$updated_content = self::update_alt_text_in_content( $updated_content, $url_mapping, $new_alt_text );
					}

					if ( $updated_content !== $content ) {
						$widgets[ $key ]['content'] = $updated_content;
						$updated                    = true;
					}
				}

				if ( $updated ) {
					update_option( $widget_option, $widgets );
					++$updated_count;
				}
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in Menüs
	 */
	private static function update_menu_items( $url_mapping, $new_alt_text ) {
		$updated_count = 0;
		$menus         = wp_get_nav_menus();

		if ( empty( $menus ) ) {
			return 0;
		}

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );

			if ( empty( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( $item->type !== 'custom' || empty( $item->url ) ) {
					continue;
				}

				foreach ( $url_mapping as $old_url => $new_url ) {
					if ( $item->url === $old_url ) {
						$args = array(
							'menu-item-url' => $new_url,
						);

						if ( ! empty( $new_alt_text ) ) {
							$args['menu-item-attr-title'] = $new_alt_text;
						}

						wp_update_nav_menu_item( $menu->term_id, $item->ID, $args );
						++$updated_count;
						break;
					}
				}
			}
		}

		return $updated_count;
	}

	/**
	 * Aktualisiert Bildverweise in WordPress Optionen
	 */
	private static function update_options( $url_mapping, $old_urls ) {
		global $wpdb;

		$updated_count = 0;

		// Erstelle LIKE-Bedingungen für alle alten URLs
		$like_conditions = array();
		foreach ( $old_urls as $old_url ) {
			$like_conditions[] = $wpdb->prepare( 'option_value LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
		}

		// Exkludiere transiente und bestimmte System-Optionen
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (each built via $wpdb->prepare() + $wpdb->esc_like() above), only imploded with a fixed ' OR ' separator; the rest of the query is fixed literal text.
		$options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
            WHERE option_name NOT LIKE '_transient%'
            AND option_name NOT LIKE '_site_transient%'
            AND (" . implode( ' OR ', $like_conditions ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $like_conditions is an array of already-prepared fragments (built via $wpdb->prepare() + $wpdb->esc_like()), only imploded with a fixed ' OR ' separator.
		);

		foreach ( $options as $option ) {
			// Serialisierte wie unserialisierte Optionswerte laufen über
			// dieselbe generische Engine (D-01); die eigene rekursive
			// Ersetzung dieser Klasse ist damit entfallen.
			list( $updated_value, $changed ) = Image_AI_SEO_Serialization_Safe_Replacer::replace( $option->option_value, $url_mapping );

			if ( $changed ) {
				// update_option() serialisiert selbst wieder, deshalb wird der
				// Wert in der Form übergeben, die WordPress erwartet.
				update_option( $option->option_name, maybe_unserialize( $updated_value ) );
				++$updated_count;
			}
		}

		return $updated_count;
	}
}
