<?php

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet die Medienspalten- und Attachment-Feld-Logik des Plugins.
 *
 * Kanonische, tatsächlich verdrahtete Implementierung (D-05): dieser Code
 * stammt 1:1 aus `ImageAiSeo` (image-ai-seo.php), der einzigen Stelle, an
 * der `manage_media_columns`/`manage_media_custom_column`/
 * `attachment_fields_to_edit` bislang per Hook registriert wurden. Das
 * strukturell identische, aber nie verdrahtete Duplikat in
 * `Image_AI_SEO_Admin_Handler::display_media_columns()` ist ersatzlos
 * entfernt, nicht übernommen.
 */
class Image_AI_SEO_Media_Columns {

	/**
	 * Initialisiert die Medienspalten- und Attachment-Feld-Hooks.
	 */
	public static function init() {
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_columns' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'display_media_columns' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_attachment_fields' ), 10, 2 );

		// MEDIA-01/D-09: native status filter dropdown + query narrowing on the
		// Media Library list table (edit.php?post_type=attachment / upload.php).
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_status_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_by_status' ) );

		// MEDIA-02: native "Optimize with AI" bulk action on the Media Library list
		// table. Registration only -- the selected IDs are handed back to the page
		// via a redirect query arg and driven by the exact same client-side
		// optimizeNextImage() loop and bulk_optimize_image AJAX action the
		// Bulk-Optimization dashboard tab already uses. No forked write logic.
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'add_bulk_optimize_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_media_bulk_action' ), 10, 3 );
	}

	/**
	 * Query-string key the selected attachment IDs are handed off through.
	 *
	 * Consumed on the next Media Library page load by assets/js/admin-dashboard.js,
	 * which parses the comma-separated list and drives the shared bulk-run loop.
	 *
	 * @var string
	 */
	const BULK_IDS_QUERY_ARG = 'image_ai_seo_bulk_ids';

	/**
	 * Query-string key of the Media Library optimization-status filter.
	 *
	 * @var string
	 */
	const STATUS_FILTER_KEY = 'image_ai_seo_status_filter';

	/**
	 * Cron hook the waiting-for-background-run state is derived from (Phase 2, D-11).
	 *
	 * @var string
	 */
	const AUTO_OPTIMIZE_CRON_HOOK = 'image_ai_seo_auto_optimize_cron';

	/**
	 * The five selectable optimization states.
	 *
	 * Order is intentional: it is the same priority order get_status_state()
	 * resolves in, and the order the filter dropdown renders. Every one of these
	 * is a real, selectable filter value -- MEDIA-01 forbids a dropdown option
	 * that does not actually narrow the list, so none of these is a no-op.
	 *
	 * @return array<string,string> state key => translated label.
	 */
	public static function get_status_labels() {
		return array(
			'needs_attention' => __( 'Needs attention', 'altimo' ),
			'reverted'        => __( 'Reverted', 'altimo' ),
			'waiting'         => __( 'Waiting for background run', 'altimo' ),
			'optimized'       => __( 'Optimized', 'altimo' ),
			'not_optimized'   => __( 'Not optimized', 'altimo' ),
		);
	}

	/**
	 * Fügt die Quick-Edit-Spalte zur Medienübersicht hinzu.
	 *
	 * @param array $columns Vorhandene Spalten.
	 * @return array Erweiterte Spalten.
	 */
	public static function add_media_columns( $columns ) {
		$columns['image_ai_seo_status'] = __( 'Optimization Status', 'altimo' );
		$columns['image_ai_seo_edit']   = __( 'Quick Edit', 'altimo' );
		return $columns;
	}

	/**
	 * Resolves exactly one of the five optimization states for an attachment.
	 *
	 * Priority order (first match wins), per D-09:
	 *   1. needs-attention flag set        -> 'needs_attention'
	 *   2. most recent History entry reverted -> 'reverted'
	 *   3. auto-optimize cron scheduled     -> 'waiting'
	 *   4. optimized flag set               -> 'optimized'
	 *   5. otherwise                        -> 'not_optimized'
	 *
	 * @param int $post_id The attachment ID.
	 * @return string One of the keys returned by get_status_labels().
	 */
	private static function get_status_state( $post_id ) {
		if ( get_post_meta( $post_id, Image_AI_SEO_Metadata_Handler::NEEDS_ATTENTION_META_KEY, true ) ) {
			return 'needs_attention';
		}

		$latest = Image_AI_SEO_History_Handler::get_history( $post_id, 1, 0 );
		if ( ! empty( $latest ) && (int) $latest[0]->is_reverted === 1 ) {
			return 'reverted';
		}

		if ( wp_next_scheduled( self::AUTO_OPTIMIZE_CRON_HOOK, array( $post_id ) ) ) {
			return 'waiting';
		}

		if ( Image_AI_SEO_Metadata_Handler::is_image_optimized( $post_id ) ) {
			return 'optimized';
		}

		return 'not_optimized';
	}

	/**
	 * Rendert den Inhalt der Quick-Edit-Spalte für ein Bild.
	 *
	 * @param string $column_name Der Name der Spalte.
	 * @param int    $post_id     Die ID des Attachments.
	 */
	public static function display_media_columns( $column_name, $post_id ) {
		// D-09: the status column is minimal on purpose -- a single labelled span
		// with a per-state class hook. Final styling belongs to Phase 7.1.
		if ( $column_name === 'image_ai_seo_status' ) {
			if ( ! wp_attachment_is_image( $post_id ) ) {
				return;
			}

			$state  = self::get_status_state( $post_id );
			$labels = self::get_status_labels();

			printf(
				'<span class="image-ai-seo-status image-ai-seo-status-%s">%s</span>',
				esc_attr( $state ),
				esc_html( isset( $labels[ $state ] ) ? $labels[ $state ] : $labels['not_optimized'] )
			);
			return;
		}

		if ( $column_name !== 'image_ai_seo_edit' ) {
			return;
		}

		// Prüfe, ob es sich um ein Bild handelt
		$post = get_post( $post_id );
		if ( ! wp_attachment_is_image( $post_id ) ) {
			return;
		}

		$metadata = Image_AI_SEO_Metadata_Handler::get_image_metadata( $post_id );

		// Trenne Dateinamen und Endung
		$filename_parts       = pathinfo( $metadata['filename'] );
		$filename_without_ext = $filename_parts['filename'];
		$file_extension       = isset( $filename_parts['extension'] ) ? '.' . $filename_parts['extension'] : '';

		printf(
			'<div class="image-ai-seo-edit" data-id="%d">
                <div class="edit-field">
                    <label>%s</label>
                    <input type="text" class="title-input" value="%s">
                </div>
                <div class="edit-field">
                    <label>%s</label>
                    <input type="text" class="alt-input" value="%s">
                </div>
                <div class="edit-field">
                    <label>%s</label>
                    <div class="filename-wrapper">
                        <input type="text" class="filename-input" value="%s">
                        <span class="file-extension">%s</span>
                    </div>
                </div>
                <div class="edit-field">
                    <label>%s</label>
                    <textarea class="description-input" rows="2">%s</textarea>
                </div>
                <div class="button-group">
                    <button class="button button-primary save-metadata">%s</button>
                    <button class="button button-secondary optimize-image">%s</button>
                </div>
            </div>',
			(int) $post_id,
			esc_html__( 'Title', 'altimo' ),
			esc_attr( $metadata['title'] ),
			esc_html__( 'Alt-Text', 'altimo' ),
			esc_attr( $metadata['alt_text'] ),
			esc_html__( 'Filename', 'altimo' ),
			esc_attr( $filename_without_ext ),
			esc_attr( $file_extension ),
			esc_html__( 'Description', 'altimo' ),
			esc_textarea( $metadata['description'] ),
			esc_html__( 'Save', 'altimo' ),
			esc_html__( 'Optimize', 'altimo' )
		);
	}

	/**
	 * Fügt Optimize-Button zu Attachment-Feldern hinzu (Grid-Modal und Bearbeitungsseite)
	 *
	 * @param array   $form_fields Vorhandene Attachment-Felder.
	 * @param WP_Post $post        Das Attachment-Post-Objekt.
	 * @return array Erweiterte Attachment-Felder.
	 */
	public static function add_attachment_fields( $form_fields, $post ) {
		// Nur für Bilder
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$is_optimized = Image_AI_SEO_Metadata_Handler::is_image_optimized( $post->ID );
		$button_class = $is_optimized ? 'button-secondary' : 'button-primary';
		$status_text  = $is_optimized ? __( 'Already optimized', 'altimo' ) : '';

		$html = sprintf(
			'<div class="image-ai-seo-attachment-field" data-attachment-id="%d">
                <button type="button" class="button %s optimize-attachment-btn" data-id="%d">
                    %s
                </button>
                <span class="optimization-status">%s</span>
                <span class="spinner"></span>
            </div>',
			$post->ID,
			$button_class,
			$post->ID,
			__( 'Optimize with AI', 'altimo' ),
			$status_text
		);

		$form_fields['image_ai_seo_optimize'] = array(
			'label' => __( 'Altimo', 'altimo' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'Optimize title, alt-text and filename using AI', 'altimo' ),
		);

		return $form_fields;
	}

	/**
	 * Renders the optimization-status filter dropdown above the Media Library list.
	 *
	 * The restrict_manage_posts action fires on every edit.php-style list table;
	 * this bails out unless it is the Media Library list table for attachments, so
	 * the dropdown never leaks onto post/page lists. The screen id for the classic
	 * Media Library list table is `upload`.
	 *
	 * @return void
	 */
	public static function render_status_filter() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table GET filter is a read-only navigation, not a state change; core's own filters (post_status, category) do the same.
		$current = isset( $_GET[ self::STATUS_FILTER_KEY ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_FILTER_KEY ] ) ) : '';

		echo '<select name="' . esc_attr( self::STATUS_FILTER_KEY ) . '" id="' . esc_attr( self::STATUS_FILTER_KEY ) . '">';
		echo '<option value="">' . esc_html__( 'All statuses', 'altimo' ) . '</option>';
		foreach ( self::get_status_labels() as $state => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $state ),
				selected( $current, $state, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Narrows the Media Library main query to the selected optimization status.
	 *
	 * Guard clauses keep this to the one query that matters: the admin
	 * attachment list's main query. T-07-06: the filter value is validated
	 * against the fixed whitelist from get_status_labels() before it is used;
	 * anything else is ignored and no narrowing is applied, so a forged value can
	 * never reach a raw query.
	 *
	 * @param WP_Query $query The query being prepared.
	 * @return void
	 */
	public static function filter_by_status( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table navigation filter, see render_status_filter().
		$state = isset( $_GET[ self::STATUS_FILTER_KEY ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_FILTER_KEY ] ) ) : '';

		if ( '' === $state || ! array_key_exists( $state, self::get_status_labels() ) ) {
			return;
		}

		foreach ( self::build_status_query_vars( $state ) as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/**
	 * Builds the query-var overlay that narrows a query to a single status.
	 *
	 * Two mechanisms, one per data shape:
	 *   - Postmeta-backed states (optimized / not_optimized / needs_attention)
	 *     narrow via a meta_query against the two boolean postmeta flags.
	 *   - Non-postmeta states (reverted / waiting) are NOT stored on the
	 *     attachment, so they are resolved into an explicit attachment-ID set and
	 *     applied as post__in. This is what makes every dropdown option actually
	 *     filter (MEDIA-01) instead of leaving reverted/waiting as no-ops. An
	 *     empty match set collapses to post__in => array( 0 ), which returns
	 *     nothing rather than (WP's post__in quirk) everything.
	 *
	 * @param string $state One of the whitelisted status keys.
	 * @return array<string,mixed> Query vars to merge into the query.
	 */
	public static function build_status_query_vars( $state ) {
		switch ( $state ) {
			case 'optimized':
				return array(
					'meta_query' => array(
						array(
							'key'     => Image_AI_SEO_Metadata_Handler::OPTIMIZATION_META_KEY,
							'value'   => '1',
							'compare' => '=',
						),
					),
				);

			case 'not_optimized':
				return array(
					'meta_query' => array(
						array(
							'key'     => Image_AI_SEO_Metadata_Handler::OPTIMIZATION_META_KEY,
							'compare' => 'NOT EXISTS',
						),
					),
				);

			case 'needs_attention':
				return array(
					'meta_query' => array(
						array(
							'key'     => Image_AI_SEO_Metadata_Handler::NEEDS_ATTENTION_META_KEY,
							'compare' => 'EXISTS',
						),
					),
				);

			case 'reverted':
				return array( 'post__in' => self::guard_id_set( self::get_reverted_attachment_ids() ) );

			case 'waiting':
				return array( 'post__in' => self::guard_id_set( self::get_waiting_attachment_ids() ) );

			default:
				return array();
		}
	}

	/**
	 * Collapses an empty match set to array( 0 ) so post__in narrows to nothing.
	 *
	 * WordPress treats an empty post__in as "no restriction" (returns all posts),
	 * which would silently turn a zero-match status filter into a no-op. array( 0 )
	 * matches no real attachment and is the standard way to force an empty result.
	 *
	 * @param int[] $ids The matched attachment IDs.
	 * @return int[]
	 */
	private static function guard_id_set( array $ids ) {
		return empty( $ids ) ? array( 0 ) : $ids;
	}

	/**
	 * Returns every image attachment whose most recent History entry is reverted.
	 *
	 * "Most recent entry reverted" mirrors get_status_state()'s reverted branch
	 * exactly, so the filter and the column can never disagree.
	 *
	 * @return int[]
	 */
	private static function get_reverted_attachment_ids() {
		$matches = array();

		foreach ( self::get_candidate_attachment_ids() as $id ) {
			$latest = Image_AI_SEO_History_Handler::get_history( $id, 1, 0 );
			if ( ! empty( $latest ) && (int) $latest[0]->is_reverted === 1 ) {
				$matches[] = $id;
			}
		}

		return $matches;
	}

	/**
	 * Returns every image attachment with a pending auto-optimize cron event.
	 *
	 * @return int[]
	 */
	private static function get_waiting_attachment_ids() {
		$matches = array();

		foreach ( self::get_candidate_attachment_ids() as $id ) {
			if ( wp_next_scheduled( self::AUTO_OPTIMIZE_CRON_HOOK, array( $id ) ) ) {
				$matches[] = $id;
			}
		}

		return $matches;
	}

	/**
	 * All image-attachment IDs, the candidate universe for the non-postmeta states.
	 *
	 * @return int[]
	 */
	private static function get_candidate_attachment_ids() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Registers the "Optimize with AI" entry in the Media Library bulk-actions dropdown.
	 *
	 * MEDIA-02: a native list-table bulk action registered via the standard
	 * bulk_actions-{screen} filter (screen id `upload`), so it sits alongside WP
	 * core's own bulk actions and inherits the core bulk-media nonce handling.
	 *
	 * @param array<string,string> $actions Existing bulk actions (key => label).
	 * @return array<string,string> Actions with the AI-optimize entry appended.
	 */
	public static function add_bulk_optimize_action( $actions ) {
		$actions['image_ai_seo_bulk_optimize'] = __( 'Optimize with AI', 'altimo' );
		return $actions;
	}

	/**
	 * Hands the selected attachment IDs back to the Media Library page after the
	 * bulk-action form POST, via a query arg on the redirect URL.
	 *
	 * This is registration/hand-off only: it performs NO optimization itself. The
	 * standard handle_bulk_actions-{screen} filter fires after WP core has already
	 * verified the bulk-media nonce and the acting user; a defense-in-depth
	 * upload_files re-check costs nothing before we build the redirect (T-07-13).
	 * The selected IDs are filtered to real images and appended as a comma-separated
	 * query arg -- the per-image bulk_optimize_image AJAX action still independently
	 * validates every ID, nonce, capability and the credit gate on each call, so a
	 * forged query string cannot bypass anything the dashboard tab does not already
	 * guard against.
	 *
	 * @param string $redirect_to The URL WP core will redirect to after the action.
	 * @param string $doaction    The selected bulk action key.
	 * @param int[]  $post_ids    The selected attachment IDs.
	 * @return string The (possibly modified) redirect URL.
	 */
	public static function handle_media_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( 'image_ai_seo_bulk_optimize' !== $doaction ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect_to;
		}

		$image_ids = array();
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id > 0 && wp_attachment_is_image( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
				$image_ids[] = $post_id;
			}
		}

		if ( empty( $image_ids ) ) {
			return $redirect_to;
		}

		return add_query_arg( self::BULK_IDS_QUERY_ARG, implode( ',', $image_ids ), $redirect_to );
	}
}
