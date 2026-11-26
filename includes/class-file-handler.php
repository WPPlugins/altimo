<?php
/**
 * Zwei-Phasen-Umbenennung der kompletten Datei-Familie (RENAME-02).
 *
 * @package Image_AI_SEO
 */

/**
 * Benennt ein Attachment samt seiner gesamten Datei-Familie um.
 *
 * Aufbau nach D-06 ("erst vollstaendig pruefen, dann aendern"):
 *
 *   verify()   - ausschliesslich lesend. Ermittelt die Datei-Familie (D-05),
 *                loest Namenskollisionen ueber wp_unique_filename() auf (D-08),
 *                verweigert ausgelagerte Medien (D-13), erkennt beschaedigte
 *                serialisierte Bestandsdaten (D-03) und liefert einen
 *                vollstaendigen Plan oder einen WP_Error zurueck -- niemals
 *                ein stilles false.
 *   write()    - benennt alle Familienmitglieder um, schreibt die Metadaten
 *                IN PLACE aus dem Plan (nie erneut ueber
 *                wp_generate_attachment_metadata()) und aktualisiert erst
 *                danach alle Referenzen.
 *   rollback() - nimmt Dateien, Metadaten UND Referenzen gemeinsam zurueck
 *                (D-07), letztere ueber den vor dem Schreiben erstellten
 *                In-Memory-Snapshot aus D-04.
 */
class Image_AI_SEO_File_Handler {

	/**
	 * Postmeta-Schluessel bekannter Optimierungs-Plugins. Nur ein hierueber
	 * bestaetigter Glob-Treffer wird mit umbenannt (D-05 / Pitfall 3): ein
	 * blosser Praefix-Treffer auf der Platte wird NIE stillschweigend
	 * mitbenannt, sondern ausgeschlossen und protokolliert.
	 *
	 * @var string[]
	 */
	private static $optimizer_meta_keys = array(
		'_shortpixel_webp',
		'_shortpixel_avif',
		'_imagify_data',
		'_ewww_image_optimizer_webp',
	);

	/**
	 * Erlaubte Endungen fuer Glob-entdeckte Geschwisterdateien (TIER 2).
	 *
	 * @var string[]
	 */
	private static $sibling_extensions = array( 'webp', 'avif', 'jpg', 'jpeg', 'png', 'gif' );

	/**
	 * Benennt ein Attachment und seine komplette Datei-Familie um.
	 *
	 * @param int         $post_id             Die Attachment-ID.
	 * @param string      $new_filename_no_ext Der neue Dateiname ohne Endung.
	 * @param string|null $old_file            Optionaler Pfad der aktuellen Hauptdatei.
	 *                                         Ohne Angabe wird get_attached_file() genutzt.
	 * @return array|WP_Error Ergebnis-Array (siehe SUMMARY) oder WP_Error mit Code
	 *                        'offload_detected' | 'rename_locked' | 'corrupted_data' |
	 *                        'preflight_failed' | 'write_failed'.
	 */
	public static function rename_attachment( $post_id, $new_filename_no_ext, $old_file = null ) {
		$post_id = (int) $post_id;

		if ( null === $old_file || '' === $old_file ) {
			$old_file = get_attached_file( $post_id );
		}

		if ( ! $old_file ) {
			return new WP_Error(
				'preflight_failed',
				__( 'No file could be determined for this image.', 'altimo' )
			);
		}

		$path_parts = pathinfo( $old_file );
		$extension  = isset( $path_parts['extension'] ) ? $path_parts['extension'] : '';
		$new_stem   = self::sanitize_target_stem( $new_filename_no_ext );

		if ( '' === $new_stem ) {
			return new WP_Error(
				'preflight_failed',
				__( 'The new filename is empty or contains no allowed characters.', 'altimo' )
			);
		}

		// Kein Umbenennen noetig -- unveraenderte Frueh-Rueckgabe wie bisher.
		if ( $path_parts['basename'] === $new_stem . '.' . $extension ) {
			return self::noop_result( $old_file );
		}

		if ( ! wp_attachment_is_image( $post_id ) ) {
			return self::noop_result( $old_file );
		}

		$lock = Image_AI_SEO_Rename_Guard::acquire_lock( $post_id );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$plan = self::verify( $post_id, $old_file, $new_stem );

			if ( is_wp_error( $plan ) ) {
				return $plan;
			}

			// D-04: vollstaendiger Snapshot VOR jedem Schreibvorgang. Er dient
			// zwei Zwecken: dem Rollback innerhalb dieses Requests (D-07) und
			// -- ueber den Rueckgabewert -- der Persistierung am
			// History-Eintrag durch den Aufrufer, ohne die der vom Nutzer
			// ausgeloeste Revert nichts wiederherzustellen haette (D-16).
			$snapshot = Image_AI_SEO_History_Handler::backup_full_state( $post_id, $plan['old_urls'] );

			/**
			 * Letzter Punkt vor dem ersten Schreibvorgang. Erweiterungspunkt
			 * und zugleich die Stelle, an der Tests eine Schreibphasen-Stoerung
			 * deterministisch einschleusen koennen.
			 *
			 * @param array $plan Der gepruefte Umbenennungsplan.
			 */
			do_action( 'image_ai_seo_before_rename_write', $plan );

			$renamed = array();
			$result  = self::write( $plan, $renamed );

			if ( is_wp_error( $result ) ) {
				self::rollback( $plan, $renamed, $snapshot );
				return $result;
			}

			// D-12: eine Weiterleitung pro tatsaechlich umbenannter Datei,
			// nicht nur fuer die Hauptdatei.
			foreach ( $plan['members'] as $member ) {
				$recorded = Image_AI_SEO_Redirect_Manager::record_rename(
					$post_id,
					self::relative_path( $member['old'] ),
					self::relative_path( $member['new'] )
				);

				// Eine verlorene Weiterleitung ist kein Grund, eine bereits
				// erfolgreiche Umbenennung zurueckzudrehen -- aber sie darf
				// auch nicht stillschweigend verschwinden. Frueher wurde der
				// Rueckgabewert komplett verworfen, sodass eine fehlende
				// Tabelle jede einzelne 301 lautlos gefressen hat.
				if ( ! $recorded ) {
					Image_AI_SEO_Plugin::log(
						sprintf(
							'Altimo: failed to record redirect for attachment %d (%s -> %s)',
							$post_id,
							self::relative_path( $member['old'] ),
							self::relative_path( $member['new'] )
						)
					);
				}
			}

			clean_post_cache( $post_id );
			clean_attachment_cache( $post_id );

			return array(
				'renamed'  => true,
				'old_file' => $plan['old_file'],
				'new_file' => $plan['new_file'],
				'members'  => $plan['members'],
				'excluded' => $plan['excluded'],
				'metadata' => $plan['metadata_new'],
				'old_urls' => $plan['old_urls'],
				'new_urls' => $plan['new_urls'],
				'stats'    => $result,
				// D-04/D-16: der Aufrufer, der den History-Eintrag anlegt,
				// persistiert diesen Snapshot per
				// Image_AI_SEO_History_Handler::store_backup() an dessen ID.
				'snapshot' => $snapshot,
			);
		} finally {
			Image_AI_SEO_Rename_Guard::release_lock( $post_id, $lock );
		}
	}

	/**
	 * Phase 1 (D-06): ausschliesslich lesend. Prueft alles, was schiefgehen
	 * kann, und liefert entweder einen vollstaendigen Plan oder einen
	 * WP_Error -- ohne dass eine einzige Datei angefasst wurde.
	 *
	 * @param int    $post_id  Die Attachment-ID.
	 * @param string $old_file Der Pfad der aktuellen Hauptdatei.
	 * @param string $new_stem Der bereits bereinigte neue Dateiname ohne Endung.
	 * @return array|WP_Error
	 */
	private static function verify( $post_id, $old_file, $new_stem ) {
		// D-13: ausgelagerte Medien werden erkannt und verweigert, bevor
		// irgendeine weitere Pruefung laeuft.
		if ( Image_AI_SEO_Rename_Guard::detect_offload( $post_id, $old_file ) ) {
			return new WP_Error(
				'offload_detected',
				__( 'This image is hosted externally (offload/CDN) and cannot be renamed. Title, alt text, and description are still optimized.', 'altimo' )
			);
		}

		// D-06: verify() ist ausschliesslich lesend. Fehlen die Metadaten
		// vollstaendig (Attachment frisch angelegt, Groessenerzeugung nie
		// gelaufen), wird hier NICHTS erzeugt und NICHTS geschrieben -- der
		// Plan beschreibt dann nur die Hauptdatei. Die Nacherzeugung uebernimmt
		// write(), nachdem jede Pruefung bestanden ist (siehe dort).
		$metadata = self::read_metadata( $post_id );

		$dir       = dirname( $old_file );
		$extension = pathinfo( $old_file, PATHINFO_EXTENSION );

		// T-04-12: das Zielverzeichnis muss innerhalb des Upload-Verzeichnisses
		// liegen. Zusammen mit dem bereinigten, pfadfreien Dateinamen schliesst
		// das jeden Traversal-Versuch aus.
		$uploads_real = self::uploads_real_path();

		if ( ! self::is_inside_uploads( $old_file, $uploads_real ) ) {
			return new WP_Error(
				'preflight_failed',
				__( 'The target directory is outside the uploads directory. The rename was aborted.', 'altimo' )
			);
		}

		// Attachment metadata is untrusted input: a poisoned `original_image`
		// or `sizes[*]['file']` value such as "../../wp-config.php" or an
		// absolute path would otherwise be joined onto $dir and turn the
		// rename into a move outside the uploads directory. WordPress itself
		// only ever stores plain basenames here, so anything else aborts the
		// rename before a single file is touched.
		if ( ! self::metadata_file_names_are_plain( $metadata ) ) {
			return new WP_Error(
				'preflight_failed',
				__( 'The image metadata contains a file path outside the uploads directory. The rename was aborted, nothing was changed.', 'altimo' )
			);
		}

		// D-08: Kollisionsaufloesung mit WordPress' eigenem Zaehler-Verhalten
		// statt einer selbstgebauten Schleife.
		$unique_name   = wp_unique_filename( $dir, $new_stem . '.' . $extension );
		$resolved_stem = pathinfo( $unique_name, PATHINFO_FILENAME );

		// Der Stamm, von dem alle Familienmitglieder ihren Namen ableiten.
		// Bei -scaled-Bildern ist das der Name des Originals, nicht der der
		// (skalierten) Hauptdatei.
		$old_stem = pathinfo( $old_file, PATHINFO_FILENAME );
		if ( ! empty( $metadata['original_image'] ) ) {
			$old_stem = pathinfo( $metadata['original_image'], PATHINFO_FILENAME );
		}

		$members = array();
		$seen    = array();

		// --- TIER 1: alles, was in den Metadaten steht (immer enthalten) ---
		$members[]         = array(
			'kind' => 'main',
			'old'  => $old_file,
			'new'  => $dir . '/' . self::swap_stem( basename( $old_file ), $old_stem, $resolved_stem ),
		);
		$seen[ $old_file ] = true;

		if ( ! empty( $metadata['original_image'] ) ) {
			$original_old = $dir . '/' . $metadata['original_image'];

			if ( ! isset( $seen[ $original_old ] ) && file_exists( $original_old ) ) {
				$members[]             = array(
					'kind' => 'original',
					'old'  => $original_old,
					'new'  => $dir . '/' . self::swap_stem( $metadata['original_image'], $old_stem, $resolved_stem ),
				);
				$seen[ $original_old ] = true;
			}
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $sizeinfo ) {
				if ( ! is_array( $sizeinfo ) || empty( $sizeinfo['file'] ) ) {
					continue;
				}

				$size_old = $dir . '/' . $sizeinfo['file'];

				if ( isset( $seen[ $size_old ] ) ) {
					continue;
				}

				// Eine in den Metadaten gefuehrte, auf der Platte fehlende
				// Groessenvariante blockiert die Umbenennung nicht: sie wird
				// uebergangen und ihr Metadaten-Eintrag bleibt unveraendert.
				if ( ! file_exists( $size_old ) ) {
					continue;
				}

				$members[]         = array(
					'kind' => 'size:' . $size,
					'old'  => $size_old,
					'new'  => $dir . '/' . self::swap_stem( $sizeinfo['file'], $old_stem, $resolved_stem ),
				);
				$seen[ $size_old ] = true;
			}
		}

		// --- TIER 2: Glob-Treffer, nur mit Bestaetigung (D-05 / Pitfall 3) ---
		$excluded           = array();
		$corroborated_names = self::collect_corroborated_sibling_names( $post_id );
		$candidates         = glob( $dir . '/' . $old_stem . '*' );

		if ( is_array( $candidates ) ) {
			foreach ( $candidates as $candidate ) {
				if ( isset( $seen[ $candidate ] ) || is_dir( $candidate ) ) {
					continue;
				}

				$candidate_ext = strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) );

				if ( ! in_array( $candidate_ext, self::$sibling_extensions, true ) ) {
					continue;
				}

				if ( ! in_array( basename( $candidate ), $corroborated_names, true ) ) {
					$excluded[] = $candidate;
					Image_AI_SEO_Plugin::log( 'Altimo: excluding uncorroborated sibling from rename: ' . $candidate );
					continue;
				}

				$members[]          = array(
					'kind' => 'sibling',
					'old'  => $candidate,
					'new'  => $dir . '/' . self::swap_stem( basename( $candidate ), $old_stem, $resolved_stem ),
				);
				$seen[ $candidate ] = true;
			}
		}

		// --- Vorabpruefung ueber ALLE Mitglieder, bevor irgendetwas passiert ---
		foreach ( $members as $member ) {
			// Every member, not only the main file's directory, must stay
			// inside the uploads directory on both ends of the rename. This
			// also catches a symlinked member that points outside uploads.
			if ( ! self::is_inside_uploads( $member['old'], $uploads_real )
				|| ! self::is_inside_uploads( $member['new'], $uploads_real ) ) {
				Image_AI_SEO_Plugin::log( 'Altimo: refusing rename, member leaves the uploads directory: ' . $member['old'] . ' -> ' . $member['new'] );

				return new WP_Error(
					'preflight_failed',
					sprintf(
						/* translators: %s: file name. */
						__( 'The file "%s" is outside the uploads directory. The rename was aborted, nothing was changed.', 'altimo' ),
						basename( $member['old'] )
					)
				);
			}

			if ( ! file_exists( $member['old'] ) ) {
				return new WP_Error(
					'preflight_failed',
					sprintf(
						/* translators: %s: file name. */
						__( 'The file "%s" no longer exists. The rename was aborted, nothing was changed.', 'altimo' ),
						basename( $member['old'] )
					)
				);
			}

			if ( ! wp_is_writable( dirname( $member['old'] ) ) ) {
				return new WP_Error(
					'preflight_failed',
					sprintf(
						/* translators: %s: directory name. */
						__( 'The directory "%s" is not writable. The rename was aborted, nothing was changed.', 'altimo' ),
						dirname( $member['old'] )
					)
				);
			}

			if ( file_exists( $member['new'] ) ) {
				return new WP_Error(
					'preflight_failed',
					sprintf(
						/* translators: %s: file name. */
						__( 'The target filename "%s" is already taken. The rename was aborted, nothing was changed.', 'altimo' ),
						basename( $member['new'] )
					)
				);
			}
		}

		// --- URL-Paare in identischer Reihenfolge ---
		$old_urls = array();
		$new_urls = array();

		foreach ( $members as $member ) {
			$old_urls[] = self::path_to_url( $member['old'] );
			$new_urls[] = self::path_to_url( $member['new'] );
		}

		// --- D-03: beschaedigte Bestandsdaten erkennen, niemals reparieren ---
		$corruption = self::detect_corrupted_references( $post_id, $old_urls );

		if ( is_wp_error( $corruption ) ) {
			return $corruption;
		}

		// --- Metadaten IN PLACE aus dem Plan neu berechnen ---
		$metadata_new = $metadata;
		$new_main     = '';

		foreach ( $members as $member ) {
			if ( 'main' === $member['kind'] ) {
				$new_main             = $member['new'];
				$metadata_new['file'] = self::relative_path( $member['new'] );
			} elseif ( 'original' === $member['kind'] ) {
				$metadata_new['original_image'] = basename( $member['new'] );
			} elseif ( 0 === strpos( $member['kind'], 'size:' ) ) {
				$size_key                                   = substr( $member['kind'], strlen( 'size:' ) );
				$metadata_new['sizes'][ $size_key ]['file'] = basename( $member['new'] );
			}
		}

		return array(
			'post_id'      => $post_id,
			'old_file'     => $old_file,
			'new_file'     => $new_main,
			'dir'          => $dir,
			'members'      => $members,
			'excluded'     => $excluded,
			'metadata_old' => $metadata,
			'metadata_new' => $metadata_new,
			'old_urls'     => $old_urls,
			'new_urls'     => $new_urls,
			'alt_text'     => get_post_meta( $post_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Phase 2: schreibt. Erst alle Dateien, dann die Metadaten aus dem Plan,
	 * dann die Referenzen. Jeder Fehlschlag -- auch der des Referenz-Updates --
	 * liefert einen WP_Error 'write_failed', woraufhin der Aufrufer rollback()
	 * ausfuehrt.
	 *
	 * @param array $plan    Der Plan aus verify().
	 * @param array $renamed Referenz: wird mit allen bereits umbenannten
	 *                       Mitgliedern befuellt, damit rollback() genau
	 *                       diese zurueckdrehen kann.
	 * @return array|WP_Error Die Statistik von update_image_references() oder ein WP_Error.
	 */
	private static function write( array $plan, array &$renamed ) {
		global $wpdb;

		$uploads_real = self::uploads_real_path();

		// Die Hauptdatei kommt zuletzt: so bleibt der Zustand bis zum letzten
		// Schritt eindeutig auf "alt" zurueckfuehrbar.
		foreach ( self::ordered_members( $plan['members'] ) as $member ) {
			// Defence in depth: verify() already checked every member, but the
			// filesystem may have changed since (e.g. a member swapped for a
			// symlink). Re-check immediately before the rename.
			if ( ! self::is_inside_uploads( $member['old'], $uploads_real )
				|| ! self::is_inside_uploads( $member['new'], $uploads_real ) ) {
				Image_AI_SEO_Plugin::log( 'Altimo: refusing to rename outside the uploads directory: ' . $member['old'] . ' -> ' . $member['new'] );

				return new WP_Error(
					'write_failed',
					__( 'A file could not be renamed. All changes were rolled back.', 'altimo' )
				);
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- native rename() is used deliberately for its atomic same-filesystem semantics, which this class's entire rollback/rename-hardening design (Phase 4, extensively characterized by PHPUnit + E2E tests) depends on; switching to WP_Filesystem::move() is a behavioral change requiring its own dedicated review, tracked as a known Plugin Check finding (09-06-SUMMARY.md), not fixed in this phase-gate task.
			if ( ! @rename( $member['old'], $member['new'] ) ) {
				Image_AI_SEO_Plugin::log( 'Altimo: Failed to rename ' . $member['old'] . ' -> ' . $member['new'] );

				return new WP_Error(
					'write_failed',
					__( 'A file could not be renamed. All changes were rolled back.', 'altimo' )
				);
			}

			$renamed[] = $member;
		}

		// Metadaten werden AUS DEM PLAN geschrieben, niemals ueber
		// wp_generate_attachment_metadata() neu erzeugt (Pattern 3).
		$metadata_to_write = $plan['metadata_new'];

		// Einzige Ausnahme, und zwar bewusst HIER statt in verify() (D-06):
		// hatte das Attachment ueberhaupt keine Metadaten, gibt es auch keine
		// Groessenvarianten, die verwaisen koennten -- die Nacherzeugung ist
		// dann unschaedlich. Sie laeuft erst, nachdem jede Pruefung bestanden
		// ist und die Dateien bereits ihren Endnamen tragen, damit kein
		// Zwischenbild unter einem Namen entsteht, den der Plan danach
		// verwirft. Sobald Metadaten existieren, ist dieser Zweig
		// unerreichbar -- eine bereits umbenannte Datei regeneriert nie
		// (04-06).
		if ( empty( $plan['metadata_old'] ) ) {
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$generated = wp_generate_attachment_metadata( $plan['post_id'], $plan['new_file'] );

			if ( ! empty( $generated ) ) {
				$metadata_to_write = $generated;
			}
		}

		wp_update_attachment_metadata( $plan['post_id'], $metadata_to_write );
		update_attached_file( $plan['post_id'], $plan['new_file'] );

		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => wp_get_attachment_url( $plan['post_id'] ) ),
			array( 'ID' => $plan['post_id'] )
		);

		// Einzige Aufrufstelle von update_image_references() im gesamten
		// Projekt (04-PATTERNS.md: "preserve, extend, don't remove").
		//
		// Datenbankfehler meldet der Referenz-Updater selbst ueber
		// $stats['db_errors']. Frueher stand hier ein `query`-Filter, der den
		// GLOBALEN Abfragestrom beobachtete -- der sah auch jede Abfrage von
		// Fremdcode an update_option, save_post, updated_post_meta oder einem
		// Object-Cache-Backend und schrieb deren Fehler der Umbenennung zu.
		// Ein unbeteiligtes Plugin konnte damit eine tatsaechlich gelungene
		// Umbenennung samt vollem Referenz-Restore zurueckrollen.
		try {
			$stats = Image_AI_SEO_Reference_Updater::update_image_references(
				$plan['post_id'],
				$plan['old_urls'],
				$plan['new_urls'],
				$plan['alt_text']
			);
		} catch ( Throwable $e ) {
			Image_AI_SEO_Plugin::log( 'Altimo: reference update threw: ' . $e->getMessage() );

			return new WP_Error(
				'write_failed',
				__( 'The references to the image could not be updated. All changes were rolled back.', 'altimo' )
			);
		}

		if ( ! empty( $stats['db_errors'] ) ) {
			Image_AI_SEO_Plugin::log( 'Altimo: reference update failed at DB level: ' . implode( ' | ', $stats['db_errors'] ) );

			return new WP_Error(
				'write_failed',
				__( 'The references to the image could not be updated. All changes were rolled back.', 'altimo' )
			);
		}

		return $stats;
	}

	/**
	 * Vollstaendiger Rollback (D-07): Dateien, Metadaten UND Referenzen
	 * kehren gemeinsam in den Ausgangszustand zurueck. Die Metadaten stammen
	 * aus dem in verify() erfassten Plan, nie aus einem erneuten Lesen des
	 * bereits geschriebenen Zustands -- genau darin bestand Defekt 2.
	 *
	 * @param array $plan     Der Plan aus verify().
	 * @param array $renamed  Die bereits umbenannten Mitglieder.
	 * @param array $snapshot Der vor dem Schreiben erfasste Referenz-Snapshot (D-04).
	 * @return void
	 */
	private static function rollback( array $plan, array $renamed, array $snapshot ) {
		global $wpdb;

		$uploads_real = self::uploads_real_path();

		foreach ( array_reverse( $renamed ) as $member ) {
			// Defence in depth: never rename back to (or from) a location
			// outside the uploads directory. Such a member is skipped and
			// logged instead, so the rest of the rollback still runs.
			if ( ! self::is_inside_uploads( $member['new'], $uploads_real )
				|| ! self::is_inside_uploads( $member['old'], $uploads_real ) ) {
				Image_AI_SEO_Plugin::log( 'Altimo: rollback skipped a member outside the uploads directory: ' . $member['new'] . ' -> ' . $member['old'] );
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Rollback ist best effort (ein Fehler darf hier nichts abbrechen); native rename() deliberately mirrors write()'s semantics (see rationale there), same deferred Plugin Check finding.
			@rename( $member['new'], $member['old'] );
		}

		wp_update_attachment_metadata( $plan['post_id'], $plan['metadata_old'] );
		update_attached_file( $plan['post_id'], $plan['old_file'] );

		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => wp_get_attachment_url( $plan['post_id'] ) ),
			array( 'ID' => $plan['post_id'] )
		);

		// Referenzen byte-identisch zurueckschreiben (04-04). Laeuft
		// bedingungslos bei jedem Rollback, unabhaengig davon, welcher
		// Teilschritt gescheitert ist.
		Image_AI_SEO_History_Handler::restore_from_snapshot( $snapshot );

		clean_post_cache( $plan['post_id'] );
		clean_attachment_cache( $plan['post_id'] );
	}

	/**
	 * Liest die Attachment-Metadaten und normalisiert sie auf ein Array.
	 *
	 * Eigene Methode, weil der Rest der Klasse mit einer offenen Metadaten-Form
	 * arbeitet (Groessenvarianten, `original_image`, Fremd-Plugin-Schluessel)
	 * und nicht mit der engen Form, die die WordPress-Stubs beschreiben.
	 *
	 * @param int $post_id Die Attachment-ID.
	 * @return array<string,mixed> Die Metadaten, leer wenn keine vorliegen.
	 */
	private static function read_metadata( $post_id ) {
		$metadata = wp_get_attachment_metadata( $post_id );

		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * Sortiert die Familienmitglieder so, dass die Hauptdatei zuletzt kommt.
	 *
	 * @param array $members Die Mitglieder aus dem Plan.
	 * @return array
	 */
	private static function ordered_members( array $members ) {
		$rest = array();
		$main = array();

		foreach ( $members as $member ) {
			if ( 'main' === $member['kind'] ) {
				$main[] = $member;
			} else {
				$rest[] = $member;
			}
		}

		return array_merge( $rest, $main );
	}

	/**
	 * Sammelt die Dateinamen, die von einem bekannten Optimierungs-Plugin
	 * ausdruecklich als eigene Geschwisterdatei benannt werden. Nur diese
	 * Namen duerfen aus einem Glob-Treffer in den Plan aufgenommen werden.
	 *
	 * @param int $post_id Die Attachment-ID.
	 * @return string[] Liste von Basisdateinamen.
	 */
	private static function collect_corroborated_sibling_names( $post_id ) {
		$names = array();

		foreach ( self::$optimizer_meta_keys as $meta_key ) {
			if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
				continue;
			}

			$value = get_post_meta( $post_id, $meta_key, true );
			self::collect_names_from_meta_value( $value, $names );
		}

		return $names;
	}

	/**
	 * Sammelt rekursiv alle Basisdateinamen aus einem Optimizer-Postmeta-Wert.
	 *
	 * @param mixed    $value Der Metawert (String oder Array).
	 * @param string[] $names Referenz auf die Sammelliste.
	 * @return void
	 */
	private static function collect_names_from_meta_value( $value, array &$names ) {
		if ( is_string( $value ) && '' !== $value ) {
			$basename = basename( $value );

			if ( '' !== pathinfo( $basename, PATHINFO_EXTENSION ) ) {
				$names[] = $basename;
			}

			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				self::collect_names_from_meta_value( $item, $names );
			}
		}
	}

	/**
	 * D-03: erkennt bereits beschaedigte serialisierte Bestandsdaten in allen
	 * Zeilen, die eine der alten URLs enthalten.
	 *
	 * Bewusst eine eigene unserialize()-Probe statt eines Aufrufs von
	 * Image_AI_SEO_Serialization_Safe_Replacer::replace(): dessen Rueckgabe
	 * [ $raw, false ] ist bei beschaedigten Daten nicht von "nichts gefunden"
	 * zu unterscheiden (dokumentiert im SUMMARY zu 04-01). Es wird nur
	 * gelesen und gemeldet, niemals repariert.
	 *
	 * @param int      $post_id  Die Attachment-ID.
	 * @param string[] $old_urls Die alten URLs der Datei-Familie.
	 * @return true|WP_Error
	 */
	private static function detect_corrupted_references( $post_id, array $old_urls ) {
		$rows = Image_AI_SEO_History_Handler::backup_full_state( $post_id, $old_urls );

		foreach ( $rows as $row ) {
			if ( ! isset( $row['raw_value'] ) || ! self::is_corrupted_serialized( $row['raw_value'] ) ) {
				continue;
			}

			return new WP_Error(
				'corrupted_data',
				sprintf(
					/* translators: 1: table name, 2: row identifier */
					__( 'Already-corrupted serialized data was found in the database (table "%1$s", entry "%2$s"). The rename was aborted, nothing was changed. Please check this entry manually.', 'altimo' ),
					isset( $row['table'] ) ? $row['table'] : '?',
					isset( $row['row_id'] ) ? (string) $row['row_id'] : '?'
				)
			);
		}

		return true;
	}

	/**
	 * Prueft, ob ein Rohwert zwar wie serialisierte Daten aussieht, sich aber
	 * nicht mehr entpacken laesst.
	 *
	 * Mehrfach verschachtelte Serialisierung wird mitgeprueft: update_post_meta()
	 * verpackt einen bereits serialisiert aussehenden String ein zweites Mal
	 * (maybe_serialize()), die Beschaedigung liegt dann eine Ebene tiefer.
	 *
	 * @param mixed $raw_value Der Rohwert aus der Datenbank.
	 * @param int   $depth     Interne Rekursionstiefe.
	 * @return bool
	 */
	private static function is_corrupted_serialized( $raw_value, $depth = 0 ) {
		if ( $depth > 5 || ! is_string( $raw_value ) || ! is_serialized( $raw_value ) ) {
			return false;
		}

		if ( 'b:0;' === trim( $raw_value ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ein Entpack-Fehler ist hier das erwartete Ergebnis, keine auszugebende Warnung.
		$unpacked = @unserialize( $raw_value, array( 'allowed_classes' => false ) );

		if ( false === $unpacked ) {
			return true;
		}

		return self::is_corrupted_serialized( $unpacked, $depth + 1 );
	}

	/**
	 * Ersetzt den fuehrenden Namensstamm eines Dateinamens.
	 *
	 * @param string $basename Der aktuelle Dateiname.
	 * @param string $old_stem Der alte Stamm.
	 * @param string $new_stem Der neue Stamm.
	 * @return string
	 */
	private static function swap_stem( $basename, $old_stem, $new_stem ) {
		if ( 0 !== strpos( $basename, $old_stem ) ) {
			return $new_stem . '-' . $basename;
		}

		return $new_stem . substr( $basename, strlen( $old_stem ) );
	}

	/**
	 * Bereinigt den gewuenschten neuen Dateinamen (T-04-12): jeder
	 * Pfadbestandteil wird entfernt, danach greift sanitize_file_name().
	 *
	 * @param string $raw Der Rohwert.
	 * @return string
	 */
	private static function sanitize_target_stem( $raw ) {
		$raw = str_replace( array( '/', '\\' ), '', (string) $raw );
		$raw = sanitize_file_name( $raw );

		return trim( $raw, '.' );
	}

	/**
	 * Resolves the real path of the uploads base directory.
	 *
	 * @return string|false The canonical uploads base directory, or false if it
	 *                      cannot be resolved.
	 */
	private static function uploads_real_path() {
		$uploads = wp_upload_dir();

		return realpath( $uploads['basedir'] );
	}

	/**
	 * Checks that a path stays inside the uploads directory.
	 *
	 * True only when all of the following hold:
	 *   - the uploads base directory could be resolved,
	 *   - basename( $path ) is a plain file name (see is_plain_file_name()),
	 *   - the real path of the containing directory exists and lies inside
	 *     the uploads base directory,
	 *   - if $path itself is a symlink, its real target exists and lies inside
	 *     the uploads base directory as well.
	 *
	 * The path itself does not have to exist, so the helper serves both the
	 * current and the target name of a rename.
	 *
	 * @param string       $path         Absolute file path to check.
	 * @param string|false $uploads_real Result of uploads_real_path().
	 * @return bool
	 */
	private static function is_inside_uploads( $path, $uploads_real ) {
		if ( ! is_string( $uploads_real ) || '' === $uploads_real || '' === $path ) {
			return false;
		}

		if ( ! self::is_plain_file_name( basename( $path ) ) ) {
			return false;
		}

		if ( ! self::is_real_path_inside( realpath( dirname( $path ) ), $uploads_real ) ) {
			return false;
		}

		if ( is_link( $path ) && ! self::is_real_path_inside( realpath( $path ), $uploads_real ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Compares a resolved path against the resolved uploads base directory.
	 *
	 * The DIRECTORY_SEPARATOR suffix on both sides prevents a sibling such as
	 * "uploads-evil" from passing as a child of "uploads".
	 *
	 * @param string|false $real_path    A realpath() result.
	 * @param string       $uploads_real The canonical uploads base directory.
	 * @return bool
	 */
	private static function is_real_path_inside( $real_path, $uploads_real ) {
		if ( ! is_string( $real_path ) || '' === $real_path ) {
			return false;
		}

		return 0 === strpos(
			rtrim( $real_path, '/\\' ) . DIRECTORY_SEPARATOR,
			rtrim( $uploads_real, '/\\' ) . DIRECTORY_SEPARATOR
		);
	}

	/**
	 * Checks that a value is a plain file name without any path component.
	 *
	 * Rejects empty values, "." and "..", and anything containing a forward
	 * slash, a backslash or a NUL byte, which also rules out absolute paths.
	 *
	 * @param mixed $name The candidate file name.
	 * @return bool
	 */
	private static function is_plain_file_name( $name ) {
		if ( ! is_string( $name ) || '' === $name || '.' === $name || '..' === $name ) {
			return false;
		}

		if ( false !== strpbrk( $name, "/\\\0" ) ) {
			return false;
		}

		return basename( $name ) === $name;
	}

	/**
	 * Checks that every member file name stored in the attachment metadata is
	 * a plain basename.
	 *
	 * Covers `original_image` and every `sizes[*]['file']`. Empty values are
	 * ignored here, because verify() skips them anyway.
	 *
	 * @param array<string,mixed> $metadata The attachment metadata.
	 * @return bool
	 */
	private static function metadata_file_names_are_plain( array $metadata ) {
		if ( ! empty( $metadata['original_image'] ) && ! self::is_plain_file_name( $metadata['original_image'] ) ) {
			return false;
		}

		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return true;
		}

		foreach ( $metadata['sizes'] as $sizeinfo ) {
			if ( ! is_array( $sizeinfo ) || empty( $sizeinfo['file'] ) ) {
				continue;
			}

			if ( ! self::is_plain_file_name( $sizeinfo['file'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ergebnis-Array fuer die Faelle, in denen bewusst nichts passiert.
	 *
	 * @param string $old_file Der unveraenderte Dateipfad.
	 * @return array
	 */
	private static function noop_result( $old_file ) {
		return array(
			'renamed'  => false,
			'old_file' => $old_file,
			'new_file' => $old_file,
			'members'  => array(),
			'excluded' => array(),
			'metadata' => array(),
			'old_urls' => array(),
			'new_urls' => array(),
			'stats'    => array(),
			'snapshot' => array(),
		);
	}

	/**
	 * Pfad relativ zum Upload-Basisverzeichnis (Format, das
	 * Image_AI_SEO_Redirect_Manager erwartet).
	 *
	 * @param string $path Absoluter Dateipfad.
	 * @return string
	 */
	private static function relative_path( $path ) {
		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );

		if ( 0 === strpos( $path, $basedir ) ) {
			return substr( $path, strlen( $basedir ) );
		}

		return ltrim( str_replace( $uploads['basedir'], '', $path ), '/\\' );
	}

	/**
	 * Oeffentliche URL zu einem Dateipfad innerhalb des Upload-Verzeichnisses.
	 *
	 * @param string $path Absoluter Dateipfad.
	 * @return string
	 */
	private static function path_to_url( $path ) {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::relative_path( $path );
	}
}
