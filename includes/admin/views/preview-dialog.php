<?php
/**
 * View: AI suggestion preview dialog (PREV-01/PREV-02/D-01/D-02/D-04).
 *
 * Hidden-by-default template, rendered once in the admin footer on every
 * screen where assets/js/admin.js is enqueued (Media Library list view, the
 * attachment edit page and the grid Media Modal). The markup is static; the
 * per-field values, the current/suggested filenames and all inline messages
 * are populated by openPreviewDialog() in assets/js/admin.js from the
 * preview-only optimize_single_image() response (Plan 07-03) BEFORE any write
 * happens.
 *
 * The dialog exposes each of the four fields (title, alt text, description,
 * filename) with its own checkbox (D-01) so the user applies exactly the
 * subset they want; only the checked field names are POSTed to
 * apply_optimize_preview() (Plan 07-03), which independently re-whitelists
 * them server-side.
 *
 * @package Image_AI_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="image-ai-seo-preview-overlay" id="image-ai-seo-preview-dialog" hidden>
	<div class="image-ai-seo-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="image-ai-seo-preview-title">
		<div class="preview-dialog-header">
			<h2 class="preview-dialog-title" id="image-ai-seo-preview-title">
				<?php esc_html_e( 'Review the AI suggestion', 'altimo' ); ?>
			</h2>
			<?php // PREV-02: the credit is spent at generation, regardless of confirm/discard. ?>
			<p class="preview-spent-credit-notice">
				<?php esc_html_e( 'The credit for this suggestion was already used when it was generated. Discarding or closing this dialog does not refund it, but reopening the preview for this image shows the same suggestion again at no extra cost.', 'altimo' ); ?>
			</p>
		</div>

		<div class="preview-dialog-body">
			<?php // The image the suggestion is for; src and filename are filled in by openPreviewDialog(). ?>
			<figure class="preview-image">
				<img class="preview-image-thumb" src="" alt="" hidden>
				<figcaption class="preview-image-caption">
					<code class="preview-image-filename"></code>
				</figcaption>
			</figure>

			<div class="preview-fields">
				<div class="preview-field" data-field="title">
					<label class="preview-field-label">
						<input type="checkbox" class="preview-field-toggle" name="fields[]" value="title" checked>
						<span><?php esc_html_e( 'Title', 'altimo' ); ?></span>
					</label>
					<input type="text" class="preview-input preview-title-input" value="" aria-label="<?php esc_attr_e( 'Title', 'altimo' ); ?>">
				</div>

				<div class="preview-field" data-field="alt_text">
					<label class="preview-field-label">
						<input type="checkbox" class="preview-field-toggle" name="fields[]" value="alt_text" checked>
						<span><?php esc_html_e( 'Alt-Text', 'altimo' ); ?></span>
					</label>
					<input type="text" class="preview-input preview-alt-input" value="" aria-label="<?php esc_attr_e( 'Alt-Text', 'altimo' ); ?>">
				</div>

				<div class="preview-field" data-field="description">
					<label class="preview-field-label">
						<input type="checkbox" class="preview-field-toggle" name="fields[]" value="description" checked>
						<span><?php esc_html_e( 'Description', 'altimo' ); ?></span>
					</label>
					<textarea class="preview-input preview-description-input" rows="4" aria-label="<?php esc_attr_e( 'Description', 'altimo' ); ?>"></textarea>
				</div>

				<?php
				// D-01: the filename is especially individually deselectable -- unchecking
				// it here is the only thing that prevents the on-disk rename. It carries its
				// own distinct styling hook (preview-field-filename) and the D-04 rename/
				// redirect notice below, unlike the three metadata-only fields above.
				?>
				<div class="preview-field preview-field-filename" data-field="filename">
					<label class="preview-field-label">
						<input type="checkbox" class="preview-field-toggle" name="fields[]" value="filename" checked>
						<span><?php esc_html_e( 'Filename', 'altimo' ); ?></span>
					</label>
					<input type="text" class="preview-input preview-filename-input" value="" aria-label="<?php esc_attr_e( 'Filename', 'altimo' ); ?>">

					<?php // D-04: announce the rename and that old URLs 301-redirect automatically. ?>
					<p class="preview-rename-notice">
						<span class="preview-rename-change">
							<?php esc_html_e( 'Current:', 'altimo' ); ?>
							<code class="preview-current-filename"></code>
							&rarr;
							<?php esc_html_e( 'Suggested:', 'altimo' ); ?>
							<code class="preview-suggested-filename"></code>
						</span>
						<span class="preview-redirect-explainer">
							<?php esc_html_e( 'When you apply the new filename the image file is renamed. Anyone (visitors and search engines) requesting the old URL is automatically forwarded to the new one with a permanent HTTP 301 redirect, so existing links keep working.', 'altimo' ); ?>
						</span>
					</p>
				</div>
			</div>
		</div>

		<div class="preview-dialog-messages" aria-live="polite"></div>

		<div class="preview-dialog-footer">
			<div class="preview-dialog-regenerate">
				<button type="button" class="button preview-regenerate-btn">
					<?php esc_html_e( 'Request a new suggestion', 'altimo' ); ?>
				</button>
				<?php // D-02: reuse the confirmRegenerate tone -- a new suggestion costs 1 credit. ?>
				<span class="preview-regenerate-warning">
					<?php esc_html_e( 'Requesting a new suggestion will use 1 credit.', 'altimo' ); ?>
				</span>
			</div>

			<div class="preview-dialog-actions">
				<button type="button" class="button button-secondary preview-discard-btn">
					<?php esc_html_e( 'Discard', 'altimo' ); ?>
				</button>
				<button type="button" class="button button-primary preview-confirm-btn">
					<?php esc_html_e( 'Apply selected fields', 'altimo' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
