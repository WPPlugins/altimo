<?php
/**
 * View: History-Tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="history" class="tab-content" role="tabpanel" aria-labelledby="nav-tab-history" tabindex="0">
	<div class="history-section">
		<h2><?php esc_html_e( 'Optimization History', 'altimo' ); ?></h2>
		<p><?php esc_html_e( 'View all image optimizations and revert changes if needed.', 'altimo' ); ?></p>

		<!-- History Filters -->
		<div class="history-filters">
			<div class="filter-row">
				<div class="filter-group filter-search">
					<label for="history-search"><?php esc_html_e( 'Search', 'altimo' ); ?></label>
					<input type="text" id="history-search" placeholder="<?php esc_attr_e( 'Search by filename...', 'altimo' ); ?>">
				</div>
				<div class="filter-group">
					<label for="history-type-filter"><?php esc_html_e( 'Type', 'altimo' ); ?></label>
					<select id="history-type-filter">
						<option value=""><?php esc_html_e( 'All types', 'altimo' ); ?></option>
						<option value="optimize"><?php esc_html_e( 'AI Optimization', 'altimo' ); ?></option>
						<option value="manual"><?php esc_html_e( 'Manual Edit', 'altimo' ); ?></option>
						<option value="revert"><?php esc_html_e( 'Reverted', 'altimo' ); ?></option>
						<option value="restore"><?php esc_html_e( 'Restored', 'altimo' ); ?></option>
						<option value="regenerate"><?php esc_html_e( 'Regenerated', 'altimo' ); ?></option>
					</select>
				</div>
				<div class="filter-group">
					<label for="history-status-filter"><?php esc_html_e( 'Status', 'altimo' ); ?></label>
					<select id="history-status-filter">
						<option value=""><?php esc_html_e( 'All statuses', 'altimo' ); ?></option>
						<option value="active"><?php esc_html_e( 'Active', 'altimo' ); ?></option>
						<option value="reverted"><?php esc_html_e( 'Reverted', 'altimo' ); ?></option>
					</select>
				</div>
				<div class="filter-group filter-actions">
					<button type="button" class="button apply-history-filters"><?php esc_html_e( 'Apply', 'altimo' ); ?></button>
					<button type="button" class="button reset-history-filters"><?php esc_html_e( 'Reset', 'altimo' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Batch Actions -->
		<div class="batch-actions" style="display: none;">
			<span class="selected-count">0 <?php esc_html_e( 'selected', 'altimo' ); ?></span>
			<button type="button" class="button batch-revert-btn"><?php esc_html_e( 'Revert Selected', 'altimo' ); ?></button>
			<button type="button" class="button batch-clear-selection"><?php esc_html_e( 'Clear Selection', 'altimo' ); ?></button>
		</div>

		<div class="history-list-container">
			<div class="history-loading">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Loading history...', 'altimo' ); ?>
			</div>
			<table class="wp-list-table widefat fixed striped history-table" style="display: none;">
				<thead>
					<tr>
						<th class="column-checkbox"><input type="checkbox" class="select-all-history" aria-label="<?php esc_attr_e( 'Select all', 'altimo' ); ?>"></th>
						<th class="column-thumbnail"><?php esc_html_e( 'Image', 'altimo' ); ?></th>
						<th class="column-changes"><?php esc_html_e( 'Changes', 'altimo' ); ?></th>
						<th class="column-type"><?php esc_html_e( 'Type', 'altimo' ); ?></th>
						<th class="column-date"><?php esc_html_e( 'Date', 'altimo' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'altimo' ); ?></th>
					</tr>
				</thead>
				<tbody class="history-items">
				</tbody>
			</table>
			<div class="history-empty" style="display: none;">
				<p><?php esc_html_e( 'No optimization history found.', 'altimo' ); ?></p>
			</div>
			<div class="history-pagination">
				<button class="button load-more-history" style="display: none;">
					<?php esc_html_e( 'Load more', 'altimo' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
