<?php

defined( 'ABSPATH' ) || exit;

/**
 * Dünner AJAX-Controller für alle vier optimize-bezogenen Actions (QUAL-02).
 *
 * Konsolidiert die zuvor doppelt registrierte `wp_ajax_image_ai_seo_optimize_image`-Action
 * (image-ai-seo.php + includes/class-admin-handler.php) auf GENAU eine
 * Implementierung und repariert dabei das Description-Feld-Bug (D-07): die
 * kanonische Vorlage war `ImageAiSeo::optimize_image()` (image-ai-seo.php:353-386,
 * MIT `description`), NICHT `Image_AI_SEO_Admin_Handler::optimize_image()`
 * (fehlte das Feld, siehe RESEARCH.md Pitfall 1).
 */
class Image_AI_SEO_Ajax_Optimize {

	/**
	 * Option key holding the state of the ONE in-flight bulk rename run (D-14).
	 *
	 * Deliberately a single option, not a job table and not a queue: D-14's scope fence
	 * says blockweise Abarbeitung only -- the full Bulk-Review-Queue stays v2 (QUEUE-01).
	 * Shape: array{ run_id: string, total: int, remaining_ids: int[], processed_ids: int[],
	 * failed_ids: int[], attempts: array<int,int> }.
	 * The option is DELETED as soon as `remaining_ids` runs empty, so a finished run can
	 * never linger and re-trigger a resume on the next page load. `attempts` is internal
	 * bookkeeping for MAX_ATTEMPTS and is never part of the AJAX response.
	 *
	 * @var string
	 */
	const PROGRESS_OPTION = 'image_ai_seo_bulk_rename_progress';

	/**
	 * How often a single image may be attempted within one run before it is retired.
	 *
	 * D-14 keeps an interrupted run resumable, which combined with the client's
	 * automatic resume on page load turned a DETERMINISTIC failure into an endless
	 * loop: the failed id stayed in `remaining_ids`, so the option was never deleted,
	 * so the next admin page load restarted the run with exactly that image -- issuing
	 * a paid generate() call every single time. A bounded attempt count makes the run
	 * terminate: an image that keeps failing moves to `failed_ids`, which resume skips,
	 * and the option is eventually cleared. Genuine interruptions (closed tab, timeout)
	 * are untouched because they never increment the counter.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Registriert die optimize-bezogenen AJAX-Actions.
	 */
	public static function init() {
		add_action( 'wp_ajax_image_ai_seo_optimize_image', array( __CLASS__, 'optimize_image' ) );
		add_action( 'wp_ajax_image_ai_seo_optimize_single_image', array( __CLASS__, 'optimize_single_image' ) );
		add_action( 'wp_ajax_image_ai_seo_apply_optimize_preview', array( __CLASS__, 'apply_optimize_preview' ) );
		add_action( 'wp_ajax_image_ai_seo_bulk_optimize_image', array( __CLASS__, 'bulk_optimize_image' ) );
		add_action( 'wp_ajax_image_ai_seo_bulk_rename_progress', array( __CLASS__, 'bulk_rename_progress' ) );
		add_action( 'wp_ajax_image_ai_seo_discard_bulk_rename_progress', array( __CLASS__, 'discard_bulk_rename_progress' ) );
		add_action( 'wp_ajax_image_ai_seo_get_unoptimized_images', array( __CLASS__, 'get_unoptimized_images' ) );
		add_action( 'wp_ajax_image_ai_seo_refresh_credit_balance', array( __CLASS__, 'refresh_credit_balance' ) );
	}

	/**
	 * AJAX Handler: Holt die aktuelle Credit-Bilanz frisch vom Backend (D-08).
	 *
	 * Wiederverwendet bewusst den bestehenden `/api/imageaiseo/validate`-Endpoint
	 * (via Image_AI_SEO_API_Validator::validate_api_key()), NICHT den optimize-
	 * Endpoint: es entsteht kein neuer serverseitiger Vertrag, und validate_api_key()
	 * aktualisiert die Option `image_ai_seo_remaining_credits` bereits selbst.
	 *
	 * D-08, Auflage 1 (GDPR-01): Der Request läuft über den einzigen Choke-Point
	 * Image_AI_SEO_Api_Client::post(), der ohne erteilten Consent gar nichts
	 * hinausschickt -- die Balance-Aktualisierung sitzt damit hinter dem Phase-6-
	 * Consent-Gate, ohne dass hier eine eigene Prüfung nötig wäre.
	 *
	 * D-08, Endpoint-Scoping: Dieser Aufruf ist bewusst NICHT durch den Credit-Gate
	 * geblockt -- Plan 07-01 beschränkt diesen Gate ausschließlich auf
	 * `/api/imageaiseo/optimize`. So kann ein Nutzer mit 0 Credits eine Aufladung
	 * überhaupt erst erkennen (Top-up-Erkennung), statt in einem Deadlock zu landen.
	 */
	public static function refresh_credit_balance() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$api_key = Image_AI_SEO_Api_Client::get_active_api_key()['key'];

		if ( '' === $api_key ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter an API key.', 'altimo' ),
				)
			);
		}

		$result = Image_AI_SEO_API_Validator::validate_api_key( $api_key );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success(
				array(
					'credits' => isset( $result['credits'] ) ? intval( $result['credits'] ) : 0,
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => isset( $result['message'] ) ? $result['message'] : __( 'Connection error. Please try again later.', 'altimo' ),
			)
		);
	}

	/**
	 * AJAX Handler: Quick-Edit-Optimize-Vorschau (Medienspalte).
	 *
	 * D-07: Ruft NUR generate() auf, KEIN apply() -- historisch (in beiden
	 * ursprünglichen Implementierungen) hat diese Action nie Metadaten
	 * geschrieben, umbenannt oder zurückgerollt; sie liest ausschließlich den
	 * Vorschlag und gibt ihn als AJAX-Response zurück, damit die Quick-Edit-
	 * Felder befüllt werden können. Der einzige Fix ist strukturell: es gibt
	 * jetzt nur noch EINEN Callback, und der liefert alle vier Felder inkl.
	 * `description`.
	 */
	public static function optimize_image() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to do this.', 'altimo' ),
				)
			);
		}

		$image_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		Image_AI_SEO_Ajax_Guard::require_attachment_edit( $image_id );

		$suggestion = Image_AI_SEO_Image_Optimizer::generate( $image_id );

		if ( $suggestion['success'] ) {
			wp_send_json_success(
				array(
					'message'     => $suggestion['message'],
					'title'       => $suggestion['data']['title'],
					'alt_text'    => $suggestion['data']['alt_text'],
					'description' => $suggestion['data']['description'], // D-07: jetzt immer gefüllt
					'filename'    => $suggestion['data']['filename'],
					'credits'     => $suggestion['data']['credits'],
				)
			);
		} else {
			// CRED-02: forward the stable `code` set by generate() on a no-credits
			// failure (image_ai_seo_no_credits) unchanged, so the quick-edit JS can
			// render a buy-link upsell CTA instead of the bare error string.
			wp_send_json_error(
				array(
					'message' => $suggestion['message'],
					'code'    => isset( $suggestion['code'] ) ? $suggestion['code'] : '',
				)
			);
		}
	}

	/**
	 * AJAX Handler: Erzeugt einen Optimierungsvorschlag zur VORSCHAU (PREV-01).
	 *
	 * Dies ist der Preview-only-Einstiegspunkt des Preview-Flows aus D-01..D-04.
	 * Anders als vor Plan 07-03 schreibt diese Action NICHTS mehr: sie ruft nur
	 * generate() auf und gibt den rohen Vorschlag zur Bestätigung zurück. Der
	 * eigentliche Schreibvorgang liegt jetzt allein in apply_optimize_preview(),
	 * das erst nach ausdrücklicher Bestätigung und nur mit den vom Nutzer gewählten
	 * Feldern schreibt.
	 *
	 * D-03 (Zwischenspeicher): Der Vorschlag wird pro Anhang in einem Transient
	 * (`image_ai_seo_preview_{id}`, 6 Stunden TTL) abgelegt, zusammen mit einem
	 * Eingabe-Hash über die vier generate()-Eingaben (per-Post aufgelöstes
	 * Keyword, additional_keywords, website_description, language). Ein zweiter
	 * Aufruf vor Ablauf und ohne geänderte Eingaben liefert den zwischengespeicherten
	 * Vorschlag zurück, OHNE generate() erneut aufzurufen -- so wird kein zweiter
	 * Credit für denselben Vorschlag verbraucht (PREV-02). Weichen die Eingaben ab,
	 * gilt der Cache als veraltet und generate() läuft erneut.
	 *
	 * D-02 (Neuen Vorschlag anfordern): Trägt der Request das Flag `regenerate`,
	 * wird der Cache bedingungslos übergangen, generate() erneut aufgerufen (ein
	 * weiterer Credit, korrekt und beabsichtigt per PREV-02), der Transient mit dem
	 * frischen Vorschlag überschrieben -- aber apply() wird NIE aufgerufen. Diese
	 * "Neuen Vorschlag anfordern"-Mechanik bleibt vollständig auf der Preview-Seite,
	 * sie ersetzt bewusst eine Wiederverwendung von History::regenerate_image()
	 * (einem Apply-Pfad, der vor jeder Bestätigung schreibt und umbenennt).
	 *
	 * Phase-3-Vertrag / Pitfall 2: Das Feld `filename` in der Preview-Antwort ist
	 * IMMER der rohe generate()-Vorschlag (endungslos), niemals der von apply()
	 * tatsächlich vergebene Name. Der aktuelle Dateiname für den D-04-Umbenennungs-
	 * hinweis kommt separat als `current_filename` frisch von der Platte.
	 */
	public static function optimize_single_image() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$image_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		Image_AI_SEO_Ajax_Guard::require_attachment_edit( $image_id );

		if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid image ID.', 'altimo' ),
				)
			);
		}

		// D-03: Eingabe-Hash über die vier generate()-Eingaben. Ändert sich einer
		// dieser Werte, ist ein zwischengespeicherter Vorschlag veraltet und wird
		// verworfen, statt einen zum inzwischen geänderten Keyword/zur geänderten
		// Beschreibung nicht mehr passenden Vorschlag anzubieten.
		$input_hash = md5(
			wp_json_encode(
				array(
					get_option( 'image_ai_seo_additional_keywords', '' ),
					get_option( 'image_ai_seo_website_description', '' ),
					get_option( 'image_ai_seo_language', 'en' ),
					Image_AI_SEO_Seo_Integration::resolve_focus_keyword( $image_id ),
				)
			)
		);

		// D-02: Force-Flag. empty() ist hier eine reine Prüfung, kein Verwenden des
		// Wertes -- keine zusätzliche Sanitisierung nötig.
		$regenerate = ! empty( $_POST['regenerate'] );

		// D-03: Cache-Treffer nur, wenn NICHT erzwungen regeneriert wird und der
		// gespeicherte Eingabe-Hash noch passt.
		if ( ! $regenerate ) {
			$cached = get_transient( 'image_ai_seo_preview_' . $image_id );

			if ( is_array( $cached ) && isset( $cached['input_hash'] ) && hash_equals( $input_hash, $cached['input_hash'] ) ) {
				self::send_preview_response( $image_id, $cached, true );
			}
		}

		// Cache-Miss, veralteter Cache oder erzwungene Regenerierung: generate()
		// aufrufen. apply() wird auf KEINEM dieser Zweige erreicht -- der Force-Pfad
		// bleibt ausdrücklich generate()-only (D-02, preview-only).
		$suggestion = Image_AI_SEO_Image_Optimizer::generate( $image_id );

		if ( ! $suggestion['success'] ) {
			// CRED-02: den von generate() gesetzten stabilen `code` (bei 0 Credits
			// image_ai_seo_no_credits) unverändert weiterreichen, damit der Preview-
			// Dialog aus Plan 07-04 einen Kauf-Link statt einer generischen Meldung
			// anzeigen kann.
			wp_send_json_error(
				array(
					'message' => $suggestion['message'],
					'code'    => isset( $suggestion['code'] ) ? $suggestion['code'] : '',
				)
			);
		}

		// Transient überschreiben (auch im Force-Fall), damit ein späteres Confirm
		// immer den neuesten Vorschlag liest, nie einen veralteten.
		$cached_data = array_merge( $suggestion['data'], array( 'input_hash' => $input_hash ) );
		set_transient( 'image_ai_seo_preview_' . $image_id, $cached_data, 6 * HOUR_IN_SECONDS );

		self::send_preview_response( $image_id, $cached_data, false );
	}

	/**
	 * Sendet die einheitliche Preview-Erfolgsantwort für optimize_single_image().
	 *
	 * Geteilt zwischen dem Cache-Treffer- und dem frisch-generierten Zweig, damit
	 * beide exakt dieselbe Antwortform liefern. `filename` ist immer der rohe
	 * generate()-Vorschlag; `current_filename` wird bei JEDEM Aufruf frisch von der
	 * Platte gelesen (nie aus den zwischengespeicherten/generierten Daten), damit der
	 * D-04-Umbenennungshinweis den echten aktuellen Namen zeigt.
	 *
	 * @param int   $image_id Die Anhang-ID.
	 * @param array $data     Vorschlagsdaten (aus generate() oder dem Cache).
	 * @param bool  $cached   True, wenn die Daten aus dem Transient stammen.
	 * @return void Beendet den Request via wp_send_json_success().
	 */
	private static function send_preview_response( $image_id, array $data, $cached ) {
		wp_send_json_success(
			array(
				'message'          => __( 'Suggestion ready. Review the changes before applying them.', 'altimo' ),
				'title'            => isset( $data['title'] ) ? $data['title'] : '',
				'alt_text'         => isset( $data['alt_text'] ) ? $data['alt_text'] : '',
				'description'      => isset( $data['description'] ) ? $data['description'] : '',
				// Phase-3-Vertrag / Pitfall 2: der ROHE generate()-Vorschlag,
				// niemals ein von apply() tatsächlich vergebener Name.
				'filename'         => isset( $data['filename'] ) ? $data['filename'] : '',
				'credits'          => isset( $data['credits'] ) ? $data['credits'] : 0,
				// D-04: aktueller Name frisch von der Platte, nie aus den Vorschlagsdaten.
				'current_filename' => basename( get_attached_file( $image_id ) ),
				// Shown next to the fields so the user sees which image the suggestion describes.
				'thumbnail_url'    => (string) wp_get_attachment_image_url( $image_id, 'medium_large' ),
				'cached'           => (bool) $cached,
			)
		);
	}

	/**
	 * AJAX Handler: Schreibt einen zuvor als Vorschau erzeugten Vorschlag nach
	 * ausdrücklicher Bestätigung (PREV-01/D-01).
	 *
	 * Dies ist ab Plan 07-03 der EINZIGE bestätigte Schreibpfad des Preview-Flows.
	 * Er liest den von optimize_single_image() zwischengespeicherten Vorschlag
	 * (`image_ai_seo_preview_{id}`) und ruft KEIN generate() als Fallback -- fehlt
	 * der Transient (abgelaufen/nie erzeugt), gibt es den `image_ai_seo_preview_expired`-
	 * Fehler, statt still einen weiteren Credit auszugeben.
	 *
	 * D-01 / T-07-10 (Feld-Whitelist): Die vom Client geschickte Feldauswahl wird
	 * serverseitig gegen die feste Whitelist array( 'title', 'alt_text', 'description',
	 * 'filename' ) per array_intersect() gefiltert. Das Client-Array wird NIEMALS
	 * unverändert an apply() durchgereicht -- jeder nicht gelistete Wert (z. B. ein
	 * eingeschmuggeltes admin_email) wird stillschweigend verworfen.
	 *
	 * apply() verlangt ein VOLLSTÄNDIGES $data-Array. Für jedes NICHT gewählte Feld
	 * wird deshalb der aktuelle Wert übernommen (Titel/Alt-Text/Beschreibung aus
	 * get_image_metadata(), Dateiname vom aktuellen Namen auf der Platte), nur die
	 * gewählten Felder werden mit dem Vorschlag überschrieben. So bleibt ein
	 * abgewählter Dateiname garantiert unumbenannt.
	 *
	 * D-03 (Cache-Lebenszyklus): Bei Erfolg wird der Transient gelöscht (einmalig pro
	 * Bestätigung -- eine neue Vorschau ruft später wieder generate() auf). Bei einem
	 * Fehlschlag (z. B. apply()s eigener Rollback) bleibt der Transient stehen, damit
	 * der Nutzer denselben bestätigten Vorschlag ohne neue Credit-Abbuchung erneut
	 * anwenden kann.
	 */
	public static function apply_optimize_preview() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$image_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		Image_AI_SEO_Ajax_Guard::require_attachment_edit( $image_id );

		if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid image ID.', 'altimo' ),
				)
			);
		}

		$cached = get_transient( 'image_ai_seo_preview_' . $image_id );

		// T-07-12: Fehlt der (nach $image_id benannte) Transient, wird NIE still mit
		// falschen Daten geschrieben -- der Nutzer muss die Vorschau neu öffnen.
		if ( ! is_array( $cached ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The preview suggestion has expired. Please reopen the preview to generate a new one.', 'altimo' ),
					'code'    => 'image_ai_seo_preview_expired',
				)
			);
		}

		// D-01/T-07-10: feste, serverseitige Whitelist. Das Client-Array wird nur
		// GESCHNITTEN, nie durchgereicht.
		$allowed_fields = array( 'title', 'alt_text', 'description', 'filename' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- jeder Eintrag wird direkt per sanitize_key() saniert und anschließend gegen $allowed_fields geschnitten.
		$posted_fields  = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array();
		$selected       = array_intersect( array_map( 'sanitize_key', $posted_fields ), $allowed_fields );

		// apply() verlangt ein vollständiges $data-Array: mit den aktuellen Werten
		// starten, nur die gewählten Felder mit dem Vorschlag überschreiben.
		$old_data         = Image_AI_SEO_Metadata_Handler::get_image_metadata( $image_id );
		$current_filename = pathinfo( basename( get_attached_file( $image_id ) ), PATHINFO_FILENAME );

		$filtered_data = array(
			'title'       => $old_data['title'],
			'alt_text'    => $old_data['alt_text'],
			'description' => $old_data['description'],
			'filename'    => $current_filename,
		);

		foreach ( $selected as $field ) {
			if ( isset( $cached[ $field ] ) ) {
				$filtered_data[ $field ] = $cached[ $field ];
			}
		}

		$result = Image_AI_SEO_Image_Optimizer::apply( $image_id, $filtered_data, $old_data, 'optimize' );

		if ( ! $result['success'] ) {
			// D-03: Transient bewusst STEHEN lassen -- Retry ohne neue Abbuchung.
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}

		// D-03: erfolgreiche Bestätigung verbraucht den Cache einmalig.
		delete_transient( 'image_ai_seo_preview_' . $image_id );

		wp_send_json_success(
			array(
				'message'     => __( 'Image successfully optimized!', 'altimo' ),
				'title'       => $result['data']['title'],
				'alt_text'    => $result['data']['alt_text'],
				'description' => isset( $result['data']['description'] ) ? $result['data']['description'] : '',
				// Phase-3-Vertrag: der TATSÄCHLICH angewendete Dateiname.
				'filename'    => $result['data']['filename'],
				// apply() reicht $filtered_data (ohne credits) durch -- der aktuelle
				// Stand steht im zwischengespeicherten Vorschlag; apply() verbraucht
				// keinen weiteren Credit.
				'credits'     => isset( $cached['credits'] ) ? intval( $cached['credits'] ) : 0,
				// RENAME-03/T-04-15: nicht-fataler Hinweis (z. B. Offload-Verweigerung).
				'notice'      => isset( $result['notice'] ) ? $result['notice'] : '',
			)
		);
	}

	/**
	 * AJAX Handler: Optimiert ein einzelnes Bild im Bulk-Modus.
	 *
	 * D-06: Ruft NUR generate() für den API-Call auf und behält die eigene
	 * Schreibreihenfolge inline bei -- keine Vereinheitlichung mit apply(), das
	 * einen anderen Vertrag hat und nur für optimize_single_image()/
	 * regenerate_image() gilt.
	 *
	 * RENAME-03 (Plan 04-07): Die Reihenfolge ist jetzt
	 * update_image_metadata() -> rename_attachment() -> log_change(). Der
	 * History-Eintrag entsteht bewusst NICHT mehr vor der Umbenennung: Er hätte
	 * sonst bei einem Fehlschlag oder einer D-13-Offload-Verweigerung einen
	 * Dateinamen behauptet, den es auf der Platte nie gab. Er trägt jetzt den
	 * tatsächlich angewendeten Namen (basename( get_attached_file() )), was
	 * zugleich der seit Phase 3 geltende `filename`-Vertrag ist.
	 *
	 * D-14 (03-CONTEXT.md): Der ursprüngliche Endungs-Verdopplungs-Bug an
	 * dieser Stelle ist bereits am Choke-Point behoben --
	 * Image_AI_SEO_Image_Optimizer::generate() liefert `filename` IMMER
	 * endungslos (siehe Plan 03-06 Task 1), egal was die API zurückgibt. Die
	 * frühere Formel (`$result['data']['filename'] . '.' . alte Endung`) ist
	 * damit entbehrlich geworden: der tatsächliche Dateiname von der Platte
	 * kann per Konstruktion keine doppelte Endung tragen.
	 * tests/integration/test-bulk-optimize-characterization.php bleibt als
	 * Regressionswächter für den Fall, dass die API selbst bereits eine Endung
	 * mitliefert (der eigentliche Auslöser des Bugs).
	 */
	public static function bulk_optimize_image() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$image_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		Image_AI_SEO_Ajax_Guard::require_attachment_edit( $image_id );

		// Prüfe ob es sich um ein Bild handelt
		$mime_type = get_post_mime_type( $image_id );
		if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'The file is not an image.', 'altimo' ),
				)
			);
		}

		// D-14: Fortschritts-Bookkeeping. Der Client schickt beim ERSTEN Aufruf eines Laufs
		// seine run_id plus die vollständige ID-Liste; danach nur noch die run_id.
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
		self::maybe_start_progress_run( $run_id, self::read_posted_image_ids() );

		// Hole die aktuellen Daten VOR der Optimierung
		$old_data = Image_AI_SEO_Metadata_Handler::get_image_metadata( $image_id );

		// Optimiere das Bild (reiner API-Call)
		$result = Image_AI_SEO_Image_Optimizer::generate( $image_id );

		if ( $result['success'] ) {
			// Speichere die optimierten Daten direkt
			Image_AI_SEO_Metadata_Handler::update_image_metadata(
				$image_id,
				$result['data']['title'],
				$result['data']['alt_text'],
				isset( $result['data']['description'] ) ? $result['data']['description'] : ''
			);

			// Benenne die Datei um
			$old_file      = get_attached_file( $image_id );
			$rename_result = Image_AI_SEO_File_Handler::rename_attachment( $image_id, $result['data']['filename'], $old_file );
			$rename_notice = '';

			if ( is_wp_error( $rename_result ) ) {
				if ( 'offload_detected' === $rename_result->get_error_code() ) {
					// D-13: Metadaten bleiben optimiert, nur der Dateiname bleibt unangetastet.
					$rename_notice = $rename_result->get_error_message();
				} else {
					// RENAME-03: Dieser Zweig existierte bisher gar nicht -- ein echter
					// Fehlschlag lief hier stillschweigend durch und ließ Metadaten und
					// Dateiname dauerhaft auseinanderlaufen. Jetzt gilt derselbe
					// Rollback-Vertrag wie in apply() und run_auto_optimize().
					Image_AI_SEO_Metadata_Handler::update_image_metadata(
						$image_id,
						$old_data['title'],
						$old_data['alt_text'],
						$old_data['description']
					);
					Image_AI_SEO_Plugin::log(
						sprintf(
							'Altimo: Failed to rename file during bulk_optimize_image for attachment %d (%s): %s',
							$image_id,
							$rename_result->get_error_code(),
							$rename_result->get_error_message()
						)
					);
					// CR-05: Fehlversuch zählen, bevor die Antwort rausgeht. Ein
					// deterministisch scheiterndes Bild (unbeschreibbares
					// Verzeichnis, dauerhaft belegter Zielname, beschädigte
					// Bestandsdaten) wird so nach MAX_ATTEMPTS ausgemustert statt
					// bei jedem Seitenaufruf erneut versucht.
					$progress = self::mark_progress_failed( $run_id, $image_id );

					// wp_send_json_error() beendet den Request selbst -- ein
					// nachgestelltes return wäre nachweislich toter Code.
					wp_send_json_error(
						array(
							'message'  => __( 'Failed to rename file. Changes have been rolled back.', 'altimo' ),
							'progress' => $progress,
						)
					);
				}
			}

			// Logge die Änderung in der Historie ERST nach der Umbenennung, mit dem
			// tatsächlich angewendeten Dateinamen (Phase-3-Vertrag). Vorher wurde die
			// History vor dem Rename mit dem bloßen Vorschlag geschrieben -- bei einem
			// Fehlschlag oder einer D-13-Verweigerung hätte der Eintrag eine
			// Umbenennung behauptet, die nie stattgefunden hat, und ein späterer
			// Revert hätte auf einen nicht existierenden Namen zurückbenannt.
			$history_id = Image_AI_SEO_History_Handler::log_change(
				$image_id,
				'optimize',
				$old_data,
				array(
					'title'       => $result['data']['title'],
					'alt_text'    => $result['data']['alt_text'],
					'filename'    => basename( get_attached_file( $image_id ) ),
					'description' => isset( $result['data']['description'] ) ? $result['data']['description'] : '',
				)
			);

			// D-04/D-16: Referenz-Snapshot am History-Eintrag persistieren,
			// sonst hat ein späterer Revert nichts wiederherzustellen.
			Image_AI_SEO_Image_Optimizer::persist_rename_backup( $history_id, $rename_result );

			// D-14: Dieses Bild ist fertig -- erst JETZT aus remaining_ids nach processed_ids
			// verschieben. Ein Fehlschlag oben lässt den Eintrag bewusst in remaining_ids
			// stehen, damit ein späterer Resume ihn erneut versucht statt ihn zu verlieren.
			$progress = self::mark_progress_processed( $run_id, $image_id );

			// Hole die aktualisierten Statistiken
			$stats = Image_AI_SEO_Metadata_Handler::get_image_optimization_stats();

			wp_send_json_success(
				array(
					'message'  => $result['message'],
					'stats'    => $stats,
					'credits'  => $result['data']['credits'],
					'notice'   => $rename_notice,
					'progress' => $progress,
				)
			);
		} else {
			$code = isset( $result['code'] ) ? $result['code'] : '';

			// Missing (or outdated) consent is not a fault of this image: nothing
			// left the site. The run stops in the browser with a clear message, and
			// the image keeps its place in remaining_ids without a counted attempt,
			// so resuming after consent was given picks it up again instead of
			// retiring it after MAX_ATTEMPTS.
			if ( 'image_ai_seo_consent_required' === $code ) {
				wp_send_json_error(
					array(
						'message'  => self::get_consent_stop_message(),
						'code'     => $code,
						'progress' => self::get_progress_state(),
					)
				);
			}

			// CR-05: siehe oben -- auch ein dauerhaft scheiternder API-Call
			// (ungültiger Key, gesperrtes Konto, nicht lesbare Datei) darf den
			// Lauf nicht unendlich offen halten.
			$progress = self::mark_progress_failed( $run_id, $image_id );

			// CRED-02: forward the stable `code` set by generate() on a no-credits
			// failure (image_ai_seo_no_credits) unchanged, so the bulk-tab JS can
			// render a buy-link upsell CTA instead of the bare error string.
			wp_send_json_error(
				array(
					'message'  => $result['message'],
					'code'     => $code,
					'progress' => $progress,
				)
			);
		}
	}

	/**
	 * Message shown when a bulk run stops because consent is missing or was
	 * given to an older version of the data-processing notice.
	 *
	 * @return string Translated message.
	 */
	public static function get_consent_stop_message() {
		if ( Image_AI_SEO_Consent_Handler::needs_reconsent() ) {
			return __( 'The bulk optimization was stopped: the data-processing notice has changed and needs your consent again. Please review it on the License tab, then resume the run. No credits were used for the remaining images.', 'altimo' );
		}

		return __( 'The bulk optimization was stopped: data-processing consent has not been granted. Please grant consent on the License tab, then resume the run. No credits were used for the remaining images.', 'altimo' );
	}

	/**
	 * AJAX Handler: Liest den Zustand des laufenden Bulk-Umbenennungslaufs (D-14).
	 *
	 * Reine Lesefunktion -- sie legt nichts an und räumt nichts auf. Der Client ruft sie
	 * beim Laden der Bulk-Seite auf: kommt eine nicht-leere `remaining_ids`-Liste zurück,
	 * wurde ein Lauf unterbrochen (Reload, Timeout, geschlossener Tab) und wird genau dort
	 * fortgesetzt statt von vorn begonnen.
	 *
	 * Optionales `run_id`: passt es nicht zum persistierten Lauf, gibt es die
	 * "kein Lauf aktiv"-Antwort. Es wird bewusst immer nur EIN Lauf verfolgt; parallele
	 * Bulk-Läufe sind nicht Teil dieses Vertrags.
	 */
	public static function bulk_rename_progress() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to do this.', 'altimo' ),
				)
			);
		}

		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
		$state  = self::get_progress_state();

		if ( null === $state || ( '' !== $run_id && $state['run_id'] !== $run_id ) ) {
			wp_send_json_success( self::empty_progress_state() );
		}

		wp_send_json_success( $state );
	}

	/**
	 * AJAX Handler: Verwirft einen unterbrochenen Bulk-Lauf, ohne ihn fortzusetzen.
	 *
	 * Gegenstück zur Bestätigungsabfrage im Dashboard: ein Lauf wird beim Seitenaufruf
	 * NICHT mehr stillschweigend fortgesetzt, weil jedes fortgesetzte Bild einen Credit
	 * kostet. Wer den Lauf bewusst abgebrochen hat, braucht deshalb einen Weg, ihn
	 * loszuwerden -- sonst bliebe nur, das Dashboard gar nicht mehr zu öffnen.
	 *
	 * Optionales `run_id`: passt es nicht zum persistierten Lauf, wird nichts gelöscht.
	 * Ohne offenen Lauf ist der Aufruf ein folgenloser Erfolg, damit ein zweiter offener
	 * Dashboard-Tab keine Fehlermeldung erzeugt.
	 */
	public static function discard_bulk_rename_progress() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to do this.', 'altimo' ),
				)
			);
		}

		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
		$state  = self::get_progress_state();

		if ( null === $state || ( '' !== $run_id && $state['run_id'] !== $run_id ) ) {
			wp_send_json_success(
				array(
					'discarded' => false,
					'run_id'    => '',
				)
			);
		}

		delete_option( self::PROGRESS_OPTION );

		wp_send_json_success(
			array(
				'discarded' => true,
				'run_id'    => $state['run_id'],
			)
		);
	}

	/**
	 * Liefert die "kein Lauf aktiv"-Antwortform.
	 *
	 * @return array{run_id: string, total: int, remaining_ids: int[], processed_ids: int[], failed_ids: int[]}
	 */
	private static function empty_progress_state() {
		return array(
			'run_id'        => '',
			'total'         => 0,
			'remaining_ids' => array(),
			'processed_ids' => array(),
			'failed_ids'    => array(),
		);
	}

	/**
	 * Liest den persistierten Fortschritt und normalisiert ihn auf die vertragliche Form.
	 *
	 * @return array{run_id: string, total: int, remaining_ids: int[], processed_ids: int[], failed_ids: int[]}|null
	 *         null, wenn kein Lauf aktiv ist (Option fehlt, ist unbrauchbar oder leer).
	 */
	private static function get_progress_state() {
		$state = get_option( self::PROGRESS_OPTION );

		if ( ! is_array( $state ) || empty( $state['run_id'] ) || empty( $state['remaining_ids'] ) ) {
			return null;
		}

		return array(
			'run_id'        => (string) $state['run_id'],
			'total'         => isset( $state['total'] ) ? intval( $state['total'] ) : 0,
			'remaining_ids' => self::to_id_list( $state['remaining_ids'] ),
			'processed_ids' => isset( $state['processed_ids'] ) ? self::to_id_list( $state['processed_ids'] ) : array(),
			'failed_ids'    => isset( $state['failed_ids'] ) ? self::to_id_list( $state['failed_ids'] ) : array(),
		);
	}

	/**
	 * Liest die Fehlversuchszähler eines Laufs (nicht Teil der Antwortform -- rein
	 * serverseitiges Bookkeeping, damit der Client sie weder sehen noch fälschen kann).
	 *
	 * @return array<int,int> Map attachment_id => Anzahl bisheriger Fehlversuche.
	 */
	private static function get_attempt_counters() {
		$state = get_option( self::PROGRESS_OPTION );

		if ( ! is_array( $state ) || ! isset( $state['attempts'] ) || ! is_array( $state['attempts'] ) ) {
			return array();
		}

		$attempts = array();

		foreach ( $state['attempts'] as $image_id => $count ) {
			$attempts[ intval( $image_id ) ] = intval( $count );
		}

		return $attempts;
	}

	/**
	 * Legt den Fortschritts-Datensatz an, wenn dieser Aufruf der erste eines Laufs ist.
	 *
	 * Erster Aufruf heißt: es gibt noch keinen persistierten Lauf, oder der persistierte
	 * gehört zu einer anderen `run_id` (der vorige Lauf wurde abgebrochen und der Nutzer
	 * startet bewusst neu -- dann gewinnt der neue Lauf). Ohne `run_id` oder ohne
	 * ID-Liste passiert nichts: ein Einzelaufruf ohne Lauf-Kontext bleibt wie bisher
	 * zustandslos.
	 *
	 * @param string $run_id    Vom Client erzeugte Lauf-Kennung.
	 * @param int[]  $image_ids Vollständige Auswahl, nur beim ersten Aufruf mitgeschickt.
	 */
	private static function maybe_start_progress_run( $run_id, array $image_ids ) {
		if ( '' === $run_id || empty( $image_ids ) ) {
			return;
		}

		$state = self::get_progress_state();

		if ( null !== $state && $state['run_id'] === $run_id ) {
			// Lauf läuft bereits -- die (erneut mitgeschickte) Liste darf ihn NICHT
			// zurücksetzen, sonst würden bereits erledigte Bilder nochmal verarbeitet.
			return;
		}

		update_option(
			self::PROGRESS_OPTION,
			array(
				'run_id'        => $run_id,
				// Die Gesamtzahl wird serverseitig aus der Liste abgeleitet, nicht vom
				// Client übernommen -- so kann `total` nie von den IDs abweichen.
				'total'         => count( $image_ids ),
				'remaining_ids' => $image_ids,
				'processed_ids' => array(),
				'failed_ids'    => array(),
				'attempts'      => array(),
			),
			false
		);
	}

	/**
	 * Verschiebt eine ID von `remaining_ids` nach `processed_ids`.
	 *
	 * Wird `remaining_ids` dabei leer, ist der Lauf beendet und die Option wird gelöscht --
	 * es bleibt kein "fertiger" Lauf zurück, den ein späterer Reload als unterbrochen
	 * missverstehen könnte.
	 *
	 * @param string $run_id   Lauf-Kennung des aktuellen Aufrufs.
	 * @param int    $image_id Gerade verarbeitete Anhang-ID.
	 * @return array{run_id: string, total: int, remaining_ids: int[], processed_ids: int[], failed_ids: int[]}
	 *         Der Zustand NACH der Aktualisierung (leere Form, wenn der Lauf fertig ist).
	 */
	private static function mark_progress_processed( $run_id, $image_id ) {
		$state = self::get_progress_state();

		if ( null === $state || '' === $run_id || $state['run_id'] !== $run_id ) {
			return self::empty_progress_state();
		}

		$attempts = self::get_attempt_counters();
		unset( $attempts[ (int) $image_id ] );

		$state['remaining_ids'] = array_values( array_diff( $state['remaining_ids'], array( $image_id ) ) );

		if ( ! in_array( $image_id, $state['processed_ids'], true ) ) {
			$state['processed_ids'][] = $image_id;
		}

		return self::write_progress_state( $state, $attempts );
	}

	/**
	 * Zählt einen Fehlversuch für ein Bild und zieht es nach MAX_ATTEMPTS aus dem Lauf.
	 *
	 * Ohne diesen Schritt blieb ein dauerhaft unverarbeitbares Bild für immer in
	 * `remaining_ids` stehen: der Lauf konnte nie fertig werden, die Option wurde nie
	 * gelöscht, und der automatische Resume beim nächsten Seitenaufruf startete ihn mit
	 * genau diesem Bild erneut -- inklusive eines erneuten, kostenpflichtigen API-Calls.
	 * Ein Bild, das MAX_ATTEMPTS mal gescheitert ist, wandert deshalb nach `failed_ids`;
	 * der Resume überspringt es, und sobald nichts mehr aussteht, verschwindet die Option.
	 *
	 * @param string $run_id   Lauf-Kennung des aktuellen Aufrufs.
	 * @param int    $image_id Gerade gescheiterte Anhang-ID.
	 * @return array{run_id: string, total: int, remaining_ids: int[], processed_ids: int[], failed_ids: int[]}
	 */
	private static function mark_progress_failed( $run_id, $image_id ) {
		$state = self::get_progress_state();

		if ( null === $state || '' === $run_id || $state['run_id'] !== $run_id ) {
			return self::empty_progress_state();
		}

		$image_id = (int) $image_id;
		$attempts = self::get_attempt_counters();

		$attempts[ $image_id ] = isset( $attempts[ $image_id ] ) ? $attempts[ $image_id ] + 1 : 1;

		if ( $attempts[ $image_id ] < self::MAX_ATTEMPTS ) {
			// Noch Versuche übrig: das Bild bleibt für einen späteren Resume stehen.
			return self::write_progress_state( $state, $attempts );
		}

		unset( $attempts[ $image_id ] );

		$state['remaining_ids'] = array_values( array_diff( $state['remaining_ids'], array( $image_id ) ) );

		if ( ! in_array( $image_id, $state['failed_ids'], true ) ) {
			$state['failed_ids'][] = $image_id;
		}

		// D-09/Pitfall 3 (RESEARCH.md): persist a DURABLE "needs attention" marker.
		// The `failed_ids` list above lives only inside the ephemeral
		// PROGRESS_OPTION, which write_progress_state() deletes the instant a run
		// finishes -- so it cannot back a Media Library column that must survive
		// page reloads. This postmeta flag does, and is cleared again by
		// Image_AI_SEO_Metadata_Handler::update_image_metadata() on the next
		// successful write, so the image is never permanently stuck.
		update_post_meta( $image_id, Image_AI_SEO_Metadata_Handler::NEEDS_ATTENTION_META_KEY, true );

		return self::write_progress_state( $state, $attempts );
	}

	/**
	 * Schreibt den Fortschritt zurück -- oder löscht ihn, wenn nichts mehr aussteht.
	 *
	 * Läuft `remaining_ids` leer, ist der Lauf beendet (erfolgreich oder mit
	 * ausgemusterten Bildern) und die Option verschwindet, damit ein späterer Reload
	 * sie nicht als unterbrochenen Lauf missversteht. Der zurückgegebene Zustand
	 * beschreibt trotzdem noch den fertigen Lauf -- insbesondere `failed_ids`, sonst
	 * verlöre die Antwort genau die Information, warum der Lauf beendet ist.
	 *
	 * @param array          $state    Der aktualisierte Zustand in Antwortform.
	 * @param array<int,int> $attempts Die Fehlversuchszähler.
	 * @return array Der Zustand NACH dem Schreiben.
	 */
	private static function write_progress_state( array $state, array $attempts ) {
		if ( empty( $state['remaining_ids'] ) ) {
			delete_option( self::PROGRESS_OPTION );

			return $state;
		}

		update_option( self::PROGRESS_OPTION, array_merge( $state, array( 'attempts' => $attempts ) ), false );

		return $state;
	}

	/**
	 * Liest die optionale `image_ids`-POST-Liste (T-04-16).
	 *
	 * Jeder Eintrag wird auf einen positiven Integer reduziert; ob die ID tatsächlich zu
	 * einem Bild gehört, entscheidet weiterhin die bestehende `get_post_mime_type()`-Prüfung
	 * beim jeweiligen Einzelaufruf -- diese Liste ist nur eine Merkliste, kein Freibrief.
	 *
	 * @return int[]
	 */
	private static function read_posted_image_ids() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- der Aufrufer bulk_optimize_image() prüft check_ajax_referer() als allererstes; der Sniff sieht nur diese Hilfsmethode.
		if ( ! isset( $_POST['image_ids'] ) || ! is_array( $_POST['image_ids'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- siehe oben; jeder Eintrag wird unmittelbar per intval() in to_id_list() sanitisiert.
		$raw = (array) wp_unslash( $_POST['image_ids'] );

		return self::to_id_list( $raw );
	}

	/**
	 * Normalisiert einen beliebigen Wert zu einer Liste eindeutiger, positiver IDs.
	 *
	 * @param mixed $value Rohwert (POST-Array oder Optionsinhalt).
	 * @return int[]
	 */
	private static function to_id_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array_map( 'intval', $value );
		$ids = array_filter(
			$ids,
			function ( $id ) {
				return $id > 0;
			}
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * AJAX Handler: Holt eine Liste aller nicht optimierten Bilder.
	 *
	 * Reine Lesefunktion, keine Optimizer-Berührung -- 1:1 unverändert aus
	 * class-admin-handler.php:843-885 übernommen.
	 */
	public static function get_unoptimized_images() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to do this.', 'altimo' ),
				)
			);
		}

		global $wpdb;

		// Hole alle Bild-IDs, die noch nicht optimiert wurden
		// 'image/%%' below is a fixed literal MIME-type prefix match (the '%%' escapes a literal '%' for $wpdb->prepare()'s own sprintf-style parsing), never user input, so there is no injectable value to pass as a replacement parameter.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                AND pm.meta_key = %s
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%%'
            AND pm.meta_value IS NULL
            ORDER BY p.ID DESC
        ",
				Image_AI_SEO_Metadata_Handler::OPTIMIZATION_META_KEY
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

		$image_ids = array_map(
			function ( $row ) {
				return intval( $row->ID );
			},
			$results
		);

		// A bulk run must only queue images the user may edit; the per-image
		// handler would reject the others anyway, one wasted round trip each.
		$image_ids = Image_AI_SEO_Ajax_Guard::filter_editable_attachments( $image_ids );

		wp_send_json_success(
			array(
				'image_ids' => $image_ids,
			)
		);
	}
}
