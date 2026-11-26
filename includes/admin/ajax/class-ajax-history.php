<?php

defined( 'ABSPATH' ) || exit;

/**
 * Dünner AJAX-Controller für die fünf History-bezogenen Actions (QUAL-01).
 *
 * 1:1 aus `includes/class-admin-handler.php:1026-1275` übernommen, keine
 * Verhaltensänderung (D-06).
 */
class Image_AI_SEO_Ajax_History {

	/**
	 * Registriert die fünf History-bezogenen AJAX-Actions.
	 */
	public static function init() {
		add_action( 'wp_ajax_image_ai_seo_get_history', array( __CLASS__, 'get_history' ) );
		add_action( 'wp_ajax_image_ai_seo_revert_change', array( __CLASS__, 'revert_change' ) );
		add_action( 'wp_ajax_image_ai_seo_restore_change', array( __CLASS__, 'restore_change' ) );
		add_action( 'wp_ajax_image_ai_seo_regenerate_image', array( __CLASS__, 'regenerate_image' ) );
		add_action( 'wp_ajax_image_ai_seo_batch_revert', array( __CLASS__, 'batch_revert' ) );
	}

	/**
	 * AJAX Handler: Holt die Historie
	 */
	public static function get_history() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$page     = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		// Filter parameters
		$filters = array();
		if ( ! empty( $_POST['search'] ) ) {
			$filters['search'] = sanitize_text_field( wp_unslash( $_POST['search'] ) );
		}
		if ( ! empty( $_POST['action_type'] ) ) {
			$filters['action_type'] = sanitize_text_field( wp_unslash( $_POST['action_type'] ) );
		}
		if ( ! empty( $_POST['status'] ) ) {
			$filters['status'] = sanitize_text_field( wp_unslash( $_POST['status'] ) );
		}

		$history = Image_AI_SEO_History_Handler::get_history( 0, $per_page, $offset, $filters );
		$total   = Image_AI_SEO_History_Handler::get_history_count( $filters );

		$items = array();
		foreach ( $history as $entry ) {
			$thumbnail_url = Image_AI_SEO_History_Handler::get_thumbnail_url( $entry->attachment_id );

			$items[] = array(
				'id'                   => $entry->id,
				'attachment_id'        => $entry->attachment_id,
				'thumbnail_url'        => $thumbnail_url,
				'action_type'          => $entry->action_type,
				'old_title'            => $entry->old_title,
				'new_title'            => $entry->new_title,
				'old_alt_text'         => $entry->old_alt_text,
				'new_alt_text'         => $entry->new_alt_text,
				'old_filename'         => $entry->old_filename,
				'new_filename'         => $entry->new_filename,
				'is_reverted'          => (bool) $entry->is_reverted,
				'created_at'           => $entry->created_at,
				'created_at_formatted' => self::format_created_at( $entry->created_at ),
			);
		}

		wp_send_json_success(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => ( $offset + $per_page ) < $total,
			)
		);
	}

	/**
	 * Formats a stored `created_at` value for the history table in the admin.
	 *
	 * `created_at` is stored in UTC (see Image_AI_SEO_History_Handler::log_change()).
	 * wp_date() converts it to the site's time zone and formats it with the
	 * site's date and time format, in the admin's language.
	 *
	 * @param string|null $created_at MySQL DATETIME in UTC.
	 * @return string The site-local date and time, or an empty string for an
	 *                empty, zero or unparsable value.
	 */
	public static function format_created_at( $created_at ) {
		if ( empty( $created_at ) || 0 === strpos( (string) $created_at, '0000-00-00' ) ) {
			return '';
		}

		$utc = date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $created_at, new DateTimeZone( 'UTC' ) );
		if ( false === $utc ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $utc->getTimestamp() );
	}

	/**
	 * AJAX Handler: Setzt eine Änderung zurück
	 */
	public static function revert_change() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$history_id = isset( $_POST['history_id'] ) ? absint( $_POST['history_id'] ) : 0;

		if ( ! $history_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid history ID.', 'altimo' ),
				)
			);
		}

		Image_AI_SEO_Ajax_Guard::require_history_entry_edit( $history_id );

		$result = Image_AI_SEO_History_Handler::revert_change( $history_id );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => $result['message'],
					// Fresh counters so the Bulk tab and its start button reflect the undo.
					'stats'   => Image_AI_SEO_Metadata_Handler::get_image_optimization_stats(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}
	}

	/**
	 * AJAX Handler: Stellt eine Änderung wieder her
	 */
	public static function restore_change() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$history_id = isset( $_POST['history_id'] ) ? absint( $_POST['history_id'] ) : 0;

		if ( ! $history_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid history ID.', 'altimo' ),
				)
			);
		}

		Image_AI_SEO_Ajax_Guard::require_history_entry_edit( $history_id );

		$result = Image_AI_SEO_History_Handler::restore_change( $history_id );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => $result['message'],
					// Fresh counters so the Bulk tab and its start button reflect the undo.
					'stats'   => Image_AI_SEO_Metadata_Handler::get_image_optimization_stats(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}
	}

	/**
	 * AJAX Handler: Generiert ein Bild neu
	 */
	public static function regenerate_image() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid attachment ID.', 'altimo' ),
				)
			);
		}

		Image_AI_SEO_Ajax_Guard::require_attachment_edit( $attachment_id );

		$result = Image_AI_SEO_History_Handler::regenerate_image( $attachment_id );

		if ( $result['success'] ) {
			// Hole die aktualisierten Credits
			$remaining_credits = get_option( 'image_ai_seo_remaining_credits', 0 );

			wp_send_json_success(
				array(
					'message' => $result['message'],
					'data'    => isset( $result['data'] ) ? $result['data'] : null,
					'credits' => $remaining_credits,
					'stats'   => Image_AI_SEO_Metadata_Handler::get_image_optimization_stats(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}
	}

	/**
	 * AJAX Handler: Batch-Revert für mehrere History-Einträge
	 */
	public static function batch_revert() {
		check_ajax_referer( 'image_ai_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No permission.', 'altimo' ),
				)
			);
		}

		$history_ids = isset( $_POST['history_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['history_ids'] ) ) : array();

		if ( empty( $history_ids ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No items selected.', 'altimo' ),
				)
			);
		}

		$success_count = 0;
		$error_count   = 0;
		$errors        = array();

		foreach ( $history_ids as $history_id ) {
			// Per-entry object check: an entry for an image the user may not
			// edit is reported as an error and skipped, the rest still runs.
			if ( ! Image_AI_SEO_Ajax_Guard::user_can_edit_attachment( Image_AI_SEO_Ajax_Guard::get_history_attachment_id( $history_id ) ) ) {
				$result = array(
					'success' => false,
					'message' => Image_AI_SEO_Ajax_Guard::get_denied_message(),
				);
			} else {
				$result = Image_AI_SEO_History_Handler::revert_change( $history_id );
			}
			if ( $result['success'] ) {
				++$success_count;
			} else {
				++$error_count;
				$errors[] = sprintf(
					/* translators: 1: history entry ID, 2: error message */
					__( 'ID %1$d: %2$s', 'altimo' ),
					$history_id,
					$result['message']
				);
			}
		}

		if ( $error_count === 0 ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: number of changes reverted */
						_n( '%d change reverted successfully.', '%d changes reverted successfully.', $success_count, 'altimo' ),
						$success_count
					),
					'stats'   => Image_AI_SEO_Metadata_Handler::get_image_optimization_stats(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: number of successful reverts, 2: number of failures */
						__( '%1$d reverted, %2$d failed.', 'altimo' ),
						$success_count,
						$error_count
					),
					'errors'  => $errors,
					'stats'   => Image_AI_SEO_Metadata_Handler::get_image_optimization_stats(),
				)
			);
		}
	}
}
