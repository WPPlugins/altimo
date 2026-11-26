<?php
/**
 * Per-attachment capability checks shared by the AJAX controllers.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an object-level `edit_post` check on top of the screen-level
 * `upload_files` check every AJAX controller already performs.
 *
 * `upload_files` only says "this user may use the media library". It does not
 * say "this user may change this particular image": an Author has
 * `upload_files` but may not edit an attachment another user uploaded. Every
 * handler that changes, optimizes or reverts a specific attachment therefore
 * also asks WordPress whether the current user may edit that attachment.
 *
 * A missing or non-existent attachment is deliberately NOT rejected here: the
 * handlers already answer those cases with their own, more specific messages
 * ("Invalid image ID.", "History entry not found." and so on), and those
 * responses stay unchanged.
 */
class Image_AI_SEO_Ajax_Guard {

	/**
	 * Whether the current user may edit the given attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if the user may edit it, or if it does not exist (left to the caller).
	 */
	public static function user_can_edit_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || ! get_post( $attachment_id ) ) {
			return true;
		}

		return current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Ends the AJAX request with a JSON error if the current user may not edit
	 * the given attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function require_attachment_edit( $attachment_id ) {
		if ( self::user_can_edit_attachment( $attachment_id ) ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => self::get_denied_message(),
			)
		);
	}

	/**
	 * Ends the AJAX request with a JSON error if the current user may not edit
	 * the attachment a history entry belongs to.
	 *
	 * @param int $history_id History entry ID.
	 * @return void
	 */
	public static function require_history_entry_edit( $history_id ) {
		$attachment_id = self::get_history_attachment_id( $history_id );

		if ( $attachment_id ) {
			self::require_attachment_edit( $attachment_id );
		}
	}

	/**
	 * Resolves the attachment a history entry belongs to.
	 *
	 * @param int $history_id History entry ID.
	 * @return int Attachment ID, or 0 if the entry does not exist.
	 */
	public static function get_history_attachment_id( $history_id ) {
		$entry = Image_AI_SEO_History_Handler::get_history_entry( absint( $history_id ) );

		return $entry ? (int) $entry->attachment_id : 0;
	}

	/**
	 * Reduces a list of attachment IDs to those the current user may edit.
	 *
	 * Users who may edit other users' posts skip the per-ID check, so an
	 * administrator's bulk run over a large library costs no extra queries.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return int[] The editable subset, original order, re-indexed.
	 */
	public static function filter_editable_attachments( array $attachment_ids ) {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return array_values( $attachment_ids );
		}

		return array_values( array_filter( $attachment_ids, array( __CLASS__, 'user_can_edit_attachment' ) ) );
	}

	/**
	 * The message shown when the per-attachment check fails.
	 *
	 * @return string Translated message.
	 */
	public static function get_denied_message() {
		return __( 'You do not have permission to edit this image.', 'altimo' );
	}
}
