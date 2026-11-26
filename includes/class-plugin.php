<?php

defined( 'ABSPATH' ) || exit;

/**
 * Zentraler, rein statischer Bootstrap des Plugins (D-03, D-05).
 *
 * Ersetzt das bisherige `ImageAiSeo`-Singleton (`image-ai-seo.php`), dessen
 * Konstruktor `Image_AI_SEO_Admin_Handler::init()` bereits beim `require_once`
 * ausführte -- VOR `plugins_loaded` -- und damit die strukturelle Wurzel des
 * QUAL-02-Bugs war (siehe 03-CONTEXT.md, "Root-Cause zu D-07").
 *
 * `boot()` ist ab jetzt der EINZIGE Ort, an dem irgendeine `Klasse::init()`
 * aufgerufen wird, und läuft ausschließlich nach `plugins_loaded`. Ein
 * zweiter Registrierungsort für denselben Hook kann es damit strukturell
 * nicht mehr geben.
 */
final class Image_AI_SEO_Plugin {

	/**
	 * Option, die den Schema-Stand der zuletzt ausgeführten Datenbank-Migration
	 * hält. Verglichen wird gegen IMAGE_AI_SEO_VERSION.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'image_ai_seo_db_version';

	/**
	 * Writes a diagnostic message to the PHP error log, but only when
	 * WP_DEBUG is enabled, so production sites are not filled with plugin
	 * diagnostics unless the site owner asked for debugging output.
	 *
	 * @param string $message Message to log (never contains secrets such as the API key).
	 * @return void
	 */
	public static function log( $message ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG above.
		error_log( (string) $message );
	}

	/**
	 * Registriert den einzigen `plugins_loaded`-Einstiegspunkt.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ) );
	}

	/**
	 * Bündelt sämtliche Hook-Registrierungen des Plugins.
	 */
	public static function boot() {
		// Muss VOR jeder Hook-Registrierung laufen: der Redirect-Layer und der
		// D-04-Backup greifen ab dem ersten Request auf Strukturen zu, die es
		// auf einer aktualisierten (statt neu aktivierten) Installation sonst
		// nicht gäbe.
		self::maybe_upgrade_db();

		// No load_plugin_textdomain(): translations come as language packs
		// from translate.wordpress.org, which WordPress loads just in time
		// from WP_LANG_DIR/plugins on its own (WP 4.6+).

		Image_AI_SEO_Admin_Menu::init();
		Image_AI_SEO_Network_Admin_Menu::init();
		Image_AI_SEO_Admin_Assets::init();
		Image_AI_SEO_Admin_Notices::init();
		Image_AI_SEO_Media_Columns::init();
		Image_AI_SEO_Ajax_Optimize::init();
		Image_AI_SEO_Ajax_Metadata::init();
		Image_AI_SEO_Ajax_History::init();
		Image_AI_SEO_Ajax_Statistics::init();
		Image_AI_SEO_Ajax_Site_Profile::init();
		Image_AI_SEO_Settings_Handler::init();
		Image_AI_SEO_History_Handler::init();

		// MULTI-01/D-05: eine später angelegte Seite braucht dieselben
		// History-/Redirect-Tabellen wie jede Seite, die es bereits zum
		// Zeitpunkt der Netzwerk-Aktivierung gab. Priorität 100, damit dieser
		// Callback nach WP-Cores eigenem Standard-Seiten-Setup läuft
		// (05-RESEARCH.md, Pattern 2). Auf einer Nicht-Multisite-Installation
		// feuert dieser Hook nie, die Registrierung ist daher unbedingt.
		add_action( 'wp_initialize_site', array( __CLASS__, 'provision_new_site' ), 100, 1 );

		// D-06: WP-Core übernimmt das eigentliche Löschen (inkl. der
		// seiteneigenen {prefix}options-Tabelle, empirisch bestätigt in
		// 05-01-SUMMARY.md); dieser Filter ergänzt nur die beiden
		// Drittanbieter-Tabellen des Plugins um die Lösch-Liste.
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'add_tables_to_drop' ), 10, 2 );

		add_filter( 'plugin_action_links_' . plugin_basename( IMAGE_AI_SEO_PLUGIN_DIR . 'altimo.php' ), array( __CLASS__, 'add_settings_link' ) );

		// GDPR-03: liefert dem Site-Betreiber einen Textbaustein fuer die eigene
		// Datenschutzerklaerung, aufgebaut aus derselben Image_AI_SEO_Consent_Disclosure-
		// Quelle wie die Checkbox-Aufklaerung (D-04), "einmal formulieren, zweimal
		// verwenden".
		add_action( 'admin_init', array( __CLASS__, 'register_privacy_policy_content' ) );

		// Auto-Optimize-Hooks (Phase 2, D-10/D-11) -- inhaltlich unverändert,
		// nur organisatorisch von der gelöschten ImageAiSeo-Instanzmethode in
		// statische Form gewandert.
		add_action( 'add_attachment', array( __CLASS__, 'maybe_schedule_optimize' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'maybe_schedule_optimize_from_metadata' ), 999, 2 );
		add_action( 'rest_after_insert_attachment', array( __CLASS__, 'maybe_schedule_optimize_rest' ) );
		add_action( 'image_ai_seo_auto_optimize_cron', array( __CLASS__, 'run_auto_optimize' ) );

		// R-01 (02-CONTEXT.md): billiger, vorwärtskompatibler Fähigkeitsprüfungs-Seam.
		// Der D-08-Spike (02-SPIKE.md, Q3) hat auf WP 7.0.2 nachgewiesen, dass keine
		// der geprüften DataViews-Registrierungs-Funktionen existiert; dieser Zweig
		// ist heute nachweislich und immer inaktiv, es wird bewusst KEINE
		// Registrierungslogik für eine künftige WP-7.1+-DataViews-API gebaut. Nur
		// die Prüfung selbst ist der Seam.
		if ( function_exists( 'wp_register_dataviews_field' ) ) {
			// Absichtlich leer: siehe Kommentar oben.
		}
	}

	/**
	 * Versionsgesteuerte Datenbank-Migration.
	 *
	 * WordPress führt `register_activation_hook()` bei einem Plugin-UPDATE
	 * nicht erneut aus (weder über die Admin-Aktualisierung noch über WP-CLI
	 * oder einen FTP-Überschreibvorgang). Ohne diese Routine existierten auf
	 * jeder bestehenden Installation weder die Redirect-Tabelle noch die
	 * Spalte `backup_data` -- jede 301-Weiterleitung (D-11/D-12) und jede
	 * D-04-Sicherung wäre dort stillschweigend verloren gegangen.
	 *
	 * Beide `create_table()`-Aufrufe laufen über `dbDelta()` und sind
	 * idempotent: eine vorhandene Tabelle wird nur um fehlende Spalten
	 * ergänzt. Die Routine läuft daher genau einmal pro Plugin-Version und
	 * ist danach ein einziger `get_option()`-Vergleich.
	 *
	 * Der Aktivierungs-Hook bleibt bestehen -- er deckt die Neuinstallation
	 * ab, diese Routine das Update.
	 *
	 * dbDelta() reports no failure, so the version is recorded only after
	 * record_db_version() has seen both tables. If a CREATE failed, the
	 * option stays at its old value and the next request tries again.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_db() {
		if ( get_option( self::DB_VERSION_OPTION ) === IMAGE_AI_SEO_VERSION ) {
			return;
		}

		Image_AI_SEO_History_Handler::create_table();
		Image_AI_SEO_Redirect_Manager::create_table();

		// 2.1.1: undo used to leave the "optimized" mark behind. Runs once per
		// version change and only touches images whose changes are all reverted.
		// It reads the history table, so it waits for the retry if that is missing.
		if ( self::tables_exist() ) {
			Image_AI_SEO_History_Handler::repair_reverted_optimized_marks();
		}

		self::record_db_version();
	}

	/**
	 * Checks that both plugin tables exist on the current site.
	 *
	 * Uses SHOW TABLES LIKE with the table name escaped for LIKE, so the
	 * underscores in the prefix and table name match literally.
	 *
	 * @return bool True when the history and the redirects table both exist.
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . Image_AI_SEO_History_Handler::TABLE_NAME,
			$wpdb->prefix . Image_AI_SEO_Redirect_Manager::TABLE_NAME,
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check right after dbDelta(), a cached answer would defeat it.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Stores the schema version for the current site, but only when both
	 * plugin tables exist.
	 *
	 * The dbDelta() call returns no error when a CREATE TABLE fails (for example on a
	 * database that rejects the statement). Recording the version anyway
	 * would make maybe_upgrade_db() skip every later attempt, so the site
	 * would stay without the table for good. Instead the option is left
	 * untouched and the failure is logged (WP_DEBUG only, see log(); the
	 * database error itself is already logged by wpdb), so the next request
	 * retries.
	 *
	 * @return bool True when the version was recorded, false when a table is missing.
	 */
	public static function record_db_version() {
		if ( ! self::tables_exist() ) {
			self::log(
				sprintf(
					'Altimo: plugin tables missing on site %d after dbDelta(), schema version not recorded; retrying on the next request.',
					get_current_blog_id()
				)
			);
			return false;
		}

		update_option( self::DB_VERSION_OPTION, IMAGE_AI_SEO_VERSION, false );
		return true;
	}

	/**
	 * Versorgt eine neu angelegte Seite mit den Plugin-Tabellen (MULTI-01,
	 * D-05). Hängt an `wp_initialize_site`, das WP Core für jede nach der
	 * Netzwerk-Aktivierung neu erstellte Seite feuert -- ohne diesen Hook
	 * hätte nur die zum Zeitpunkt der Aktivierung existierende Seitenmenge
	 * ihre Tabellen (die `image_ai_seo_provision_network()`-Schleife in
	 * altimo.php deckt ausschließlich die Bestandsseiten ab).
	 *
	 * Ruft dieselben, unveränderten `create_table()`-Methoden wie die
	 * Aktivierung auf; hier wird keine neue DDL eingeführt.
	 *
	 * @param WP_Site $new_site Die neu angelegte Seite.
	 */
	public static function provision_new_site( $new_site ) {
		switch_to_blog( (int) $new_site->blog_id );
		Image_AI_SEO_History_Handler::create_table();
		Image_AI_SEO_Redirect_Manager::create_table();
		restore_current_blog();
	}

	/**
	 * Ergänzt die vom `wpmu_drop_tables`-Filter verwaltete Lösch-Liste um die
	 * beiden Plugin-Tabellen der gelöschten Seite (D-06).
	 *
	 * 05-01-SUMMARY.md hat empirisch bestätigt, dass `wp_delete_site()` die
	 * seiteneigene `{prefix}options`-Tabelle (und damit jede dort abgelegte
	 * Plugin-Option) bereits von sich aus entfernt -- ausschließlich
	 * Drittanbieter-Tabellen wie die hier ergänzten überleben sonst als
	 * Datenleichen. Ein zusätzlicher `wp_delete_site`-Options-Cleanup-Hook
	 * ist deshalb bewusst NICHT implementiert.
	 *
	 * WICHTIG (05-RESEARCH.md, Pitfall 3): dieser Filter wechselt selbst
	 * nicht die Seite, `$wpdb->prefix` würde daher den Präfix der aktuell
	 * aktiven statt der zu löschenden Seite liefern. Es muss zwingend
	 * `$wpdb->get_blog_prefix( $site_id )` verwendet werden.
	 *
	 * @param string[] $tables  Bisherige Liste der zu löschenden Tabellen.
	 * @param int      $site_id ID der zu löschenden Seite.
	 * @return string[] Erweiterte Liste der zu löschenden Tabellen.
	 */
	public static function add_tables_to_drop( $tables, $site_id ) {
		global $wpdb;

		$tables[] = $wpdb->get_blog_prefix( $site_id ) . Image_AI_SEO_History_Handler::TABLE_NAME;
		$tables[] = $wpdb->get_blog_prefix( $site_id ) . Image_AI_SEO_Redirect_Manager::TABLE_NAME;

		return $tables;
	}

	/**
	 * Registriert den D-04-Aufklaerungstext ueber wp_add_privacy_policy_content()
	 * (GDPR-03), aufrufbar ausschliesslich waehrend/nach admin_init.
	 *
	 * Baut den Inhalt aus Image_AI_SEO_Consent_Disclosure's Gettern zusammen
	 * (statt neuen Prosa-Text zu erfinden) -- derselbe Wortlaut, der in Phase 9
	 * (DIST-04) in den readme-Abschnitt "External services" wandert.
	 */
	public static function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		// get_detail_text() is already run through wp_kses_post() and is the
		// same text the consent checkbox shows, so the suggested policy text can
		// never drift from what the site owner agreed to.
		$content = Image_AI_SEO_Consent_Disclosure::get_detail_text();

		wp_add_privacy_policy_content(
			__( 'Altimo – AI Image SEO', 'altimo' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Fügt einen Einstellungs-Link zur Plugin-Übersicht hinzu.
	 *
	 * @param array $links Vorhandene Links.
	 * @return array Erweiterte Links.
	 */
	public static function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=altimo' ),
			__( 'Settings', 'altimo' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Auto-Optimize-Trigger: add_attachment (klassischer Upload).
	 *
	 * D-10: Einer von drei Hooks, die dasselbe Attachment planen können; die
	 * Idempotenz lebt in schedule_if_needed(), nicht hier.
	 *
	 * @param int $attachment_id Die ID des hochgeladenen Bildes.
	 */
	public static function maybe_schedule_optimize( $attachment_id ) {
		self::schedule_if_needed( $attachment_id );
	}

	/**
	 * Auto-Optimize-Trigger: wp_generate_attachment_metadata (Bildgrößen fertig).
	 *
	 * Filter-Hook: MUSS $metadata unverändert zurückgeben.
	 *
	 * @param array $metadata       Die generierten Attachment-Metadaten.
	 * @param int   $attachment_id  Die ID des Attachments.
	 * @return array Unverändertes $metadata.
	 */
	public static function maybe_schedule_optimize_from_metadata( $metadata, $attachment_id ) {
		self::schedule_if_needed( $attachment_id );
		return $metadata;
	}

	/**
	 * Auto-Optimize-Trigger: rest_after_insert_attachment (REST-Upload).
	 *
	 * @param WP_Post $attachment Das eingefügte Attachment-Objekt.
	 */
	public static function maybe_schedule_optimize_rest( $attachment ) {
		self::schedule_if_needed( $attachment->ID );
	}

	/**
	 * Plant einmalig ein WP-Cron-Event für die Hintergrund-Optimierung (D-11),
	 * abgesichert gegen Mehrfachplanung durch mehrere Upload-Hooks (D-10).
	 *
	 * @param int $attachment_id Die ID des Attachments.
	 */
	private static function schedule_if_needed( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		// Prüfe, ob Auto-Optimierung aktiviert ist.
		if ( ! get_option( 'image_ai_seo_auto_optimize', false ) ) {
			return;
		}

		// Prüfe, ob es sich um ein Bild handelt.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		// Bereits optimiert (Idempotenz-Check zur Planungszeit).
		if ( Image_AI_SEO_Metadata_Handler::is_image_optimized( $attachment_id ) ) {
			return;
		}

		// Bereits geplant (Schutz gegen Mehrfachplanung durch add_attachment,
		// wp_generate_attachment_metadata und rest_after_insert_attachment).
		if ( wp_next_scheduled( 'image_ai_seo_auto_optimize_cron', array( $attachment_id ) ) ) {
			return;
		}

		// GDPR-01/D-09: kein Consent, kein Cron-Event. Es wird an dieser Stelle
		// noch nichts markiert, daher gibt es auch nichts zurückzunehmen --
		// das Bild bleibt nachholbar, sobald Consent später erteilt wird.
		if ( ! Image_AI_SEO_Consent_Handler::is_granted() ) {
			return;
		}

		$scheduled = wp_schedule_single_event( time(), 'image_ai_seo_auto_optimize_cron', array( $attachment_id ) );

		// A failed scheduling attempt would otherwise be completely silent: the
		// attachment is never optimized and nothing anywhere records why.
		if ( false === $scheduled ) {
			self::log(
				sprintf(
					'Altimo: Failed to schedule auto-optimize cron event for attachment %d',
					$attachment_id
				)
			);
		}
	}

	/**
	 * Cron-Callback: führt die eigentliche Bild-Optimierung im Hintergrund aus (D-11).
	 *
	 * Validiert das übergebene attachment_id-Argument erneut (der Cron-Kontext
	 * hat keinen Request-/Session-Bezug mehr) und prüft die Idempotenz erneut
	 * zur Ausführungszeit, nicht nur zur Planungszeit.
	 *
	 * @param int $attachment_id Die ID des zu optimierenden Attachments.
	 */
	public static function run_auto_optimize( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || ! get_post( $attachment_id ) ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		// Erneuter Idempotenz-Check zur Ausführungszeit (z. B. falls das Bild
		// zwischenzeitlich manuell optimiert wurde).
		if ( Image_AI_SEO_Metadata_Handler::is_image_optimized( $attachment_id ) ) {
			return;
		}

		// GDPR-01/D-09: erneuter Consent-Check zur Ausführungszeit. Zwischen
		// Planung und Cron-Ausführung können Minuten oder Stunden liegen --
		// wurde Consent in diesem Fenster widerrufen, darf der Lauf hier
		// nicht mehr fortfahren (defensive re-check, deckt Pitfall 4 ab).
		if ( ! Image_AI_SEO_Consent_Handler::is_granted() ) {
			return;
		}

		// Zustand VOR der Optimierung sichern (Rollback-Basis, analog zum
		// synchronen Pfad in optimize_single_image()).
		$old_data = Image_AI_SEO_Metadata_Handler::get_image_metadata( $attachment_id );
		$old_file = get_attached_file( $attachment_id );

		// Optimiere das Bild.
		$result = Image_AI_SEO_Image_Optimizer::generate( $attachment_id );

		if ( ! $result['success'] ) {
			// Der Cron-Pfad hat keinerlei UI: ohne Logeintrag wäre ein
			// fehlgeschlagener API-Call (ungültiger Key, Rate-Limit, Timeout)
			// vollständig unsichtbar.
			self::log(
				sprintf(
					'Altimo: run_auto_optimize failed for attachment %d: %s',
					$attachment_id,
					isset( $result['message'] ) ? $result['message'] : 'unknown error'
				)
			);
			return;
		}

		$description = isset( $result['data']['description'] ) ? $result['data']['description'] : '';

		// Speichere die optimierten Daten (markiert das Bild intern bereits als
		// optimiert -- wird bei fehlgeschlagenem Rename unten zurückgenommen).
		Image_AI_SEO_Metadata_Handler::update_image_metadata(
			$attachment_id,
			$result['data']['title'],
			$result['data']['alt_text'],
			$description
		);

		// Benenne die Datei um.
		$new_filename  = $result['data']['filename'];
		$rename_result = Image_AI_SEO_File_Handler::rename_attachment( $attachment_id, $new_filename, $old_file );

		if ( is_wp_error( $rename_result ) ) {
			if ( 'offload_detected' === $rename_result->get_error_code() ) {
				// D-13: Ausgelagerte Medien behalten ihren Dateinamen, die
				// optimierten Metadaten bleiben aber bestehen und der Lauf gilt als
				// erfolgreich. Der Cron-Pfad hat keine UI, deshalb ist das Log die
				// einzige Stelle, an der die Verweigerung sichtbar wird.
				self::log(
					sprintf(
						'Altimo: run_auto_optimize kept metadata but skipped the rename for attachment %d: %s',
						$attachment_id,
						$rename_result->get_error_message()
					)
				);
			} else {
				// CR-01 (Phase 2), unverändert: Titel/Alt-Text/Beschreibung
				// zurückrollen, damit Metadaten und Dateiname nicht dauerhaft
				// auseinanderlaufen.
				Image_AI_SEO_Metadata_Handler::update_image_metadata(
					$attachment_id,
					$old_data['title'],
					$old_data['alt_text'],
					$old_data['description']
				);

				// Optimiert-Markierung ebenfalls zurücknehmen, sonst blockiert der
				// Idempotenz-Guard in schedule_if_needed() jeden späteren Versuch
				// dauerhaft (kein Retry-Pfad mehr).
				self::unmark_optimized( $attachment_id, $old_data['is_optimized'] );

				self::log(
					sprintf(
						'Altimo: Failed to rename file during run_auto_optimize for attachment %d (%s): %s',
						$attachment_id,
						$rename_result->get_error_code(),
						$rename_result->get_error_message()
					)
				);
				return;
			}
		}

		// Historie erst nach erfolgreichen Operationen schreiben, identisch zum
		// synchronen Pfad -- so ist ein Auto-Optimize-Lauf revidierbar.
		$history_id = Image_AI_SEO_History_Handler::log_change(
			$attachment_id,
			'optimize',
			$old_data,
			array(
				'title'       => $result['data']['title'],
				'alt_text'    => $result['data']['alt_text'],
				'filename'    => basename( get_attached_file( $attachment_id ) ),
				'description' => $description,
			)
		);

		// D-04/D-16: Referenz-Snapshot am History-Eintrag persistieren,
		// sonst hat ein späterer Revert nichts wiederherzustellen.
		Image_AI_SEO_Image_Optimizer::persist_rename_backup( $history_id, $rename_result );

		// Leere den Cache.
		clean_post_cache( $attachment_id );
		clean_attachment_cache( $attachment_id );
	}

	/**
	 * Nimmt die Optimiert-Markierung eines Attachments zurück (Rollback), inkl.
	 * Korrektur des Gesamtzählers -- aber nur, wenn dieser Lauf die Markierung
	 * überhaupt selbst gesetzt hat.
	 *
	 * @param int  $attachment_id       Die ID des Attachments.
	 * @param bool $was_optimized_before Ob das Bild schon vor diesem Lauf als optimiert galt.
	 */
	private static function unmark_optimized( $attachment_id, $was_optimized_before ) {
		if ( $was_optimized_before ) {
			return;
		}

		Image_AI_SEO_Metadata_Handler::unmark_image_as_optimized( $attachment_id );
	}
}
