<?php
/**
 * View: Statistics-Tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Statistics Tab -->
<div id="statistics" class="tab-content" role="tabpanel" aria-labelledby="nav-tab-statistics" tabindex="0">
	<div class="statistics-section">
		<h2><?php esc_html_e( 'Statistics Dashboard', 'altimo' ); ?></h2>
		<p><?php esc_html_e( 'Overview of your image optimization activity.', 'altimo' ); ?></p>

		<div class="statistics-loading">
			<span class="spinner is-active"></span>
			<?php esc_html_e( 'Loading statistics...', 'altimo' ); ?>
		</div>

		<div class="statistics-content" style="display: none;">
			<!-- Image Stats -->
			<div class="stats-grid">
				<div class="stat-card stat-card-primary">
					<div class="stat-icon dashicons dashicons-format-image"></div>
					<div class="stat-details">
						<span class="stat-value" id="stat-total-images">0</span>
						<span class="stat-label"><?php esc_html_e( 'Total Images', 'altimo' ); ?></span>
					</div>
				</div>
				<div class="stat-card stat-card-success">
					<div class="stat-icon dashicons dashicons-yes-alt"></div>
					<div class="stat-details">
						<span class="stat-value" id="stat-optimized-images">0</span>
						<span class="stat-label"><?php esc_html_e( 'Optimized', 'altimo' ); ?></span>
					</div>
				</div>
				<div class="stat-card stat-card-warning">
					<div class="stat-icon dashicons dashicons-warning"></div>
					<div class="stat-details">
						<span class="stat-value" id="stat-unoptimized-images">0</span>
						<span class="stat-label"><?php esc_html_e( 'Unoptimized', 'altimo' ); ?></span>
					</div>
				</div>
				<div class="stat-card stat-card-info">
					<div class="stat-icon dashicons dashicons-chart-bar"></div>
					<div class="stat-details">
						<span class="stat-value" id="stat-optimization-rate">0%</span>
						<span class="stat-label"><?php esc_html_e( 'Optimization Rate', 'altimo' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Progress Bar -->
			<div class="optimization-progress-overview">
				<h3><?php esc_html_e( 'Optimization Progress', 'altimo' ); ?></h3>
				<div class="progress-bar-container">
					<div class="progress-bar-fill" id="optimization-progress-bar"></div>
				</div>
				<div class="progress-labels">
					<span id="progress-optimized-label">0</span>
					<span id="progress-total-label">0</span>
				</div>
			</div>

			<!-- Credits Stats -->
			<div class="stats-section">
				<h3><?php esc_html_e( 'Credit Usage', 'altimo' ); ?></h3>
				<div class="stats-grid stats-grid-small">
					<div class="stat-card-small">
						<span class="stat-value-small" id="stat-credits-remaining">0</span>
						<span class="stat-label-small"><?php esc_html_e( 'Remaining', 'altimo' ); ?></span>
					</div>
					<div class="stat-card-small">
						<span class="stat-value-small" id="stat-credits-today">0</span>
						<span class="stat-label-small"><?php esc_html_e( 'Used Today', 'altimo' ); ?></span>
					</div>
					<div class="stat-card-small">
						<span class="stat-value-small" id="stat-credits-month">0</span>
						<span class="stat-label-small"><?php esc_html_e( 'This Month', 'altimo' ); ?></span>
					</div>
					<div class="stat-card-small">
						<span class="stat-value-small" id="stat-credits-total">0</span>
						<span class="stat-label-small"><?php esc_html_e( 'Total Used', 'altimo' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Activity Chart -->
			<div class="stats-section">
				<h3><?php esc_html_e( 'Activity (Last 7 Days)', 'altimo' ); ?></h3>
				<div class="activity-chart" id="activity-chart">
					<!-- Chart bars will be inserted here by JavaScript -->
				</div>
			</div>

			<!-- History Stats -->
			<div class="stats-section">
				<h3><?php esc_html_e( 'Action Breakdown', 'altimo' ); ?></h3>
				<div class="action-breakdown">
					<div class="action-stat">
						<span class="action-label"><?php esc_html_e( 'AI Optimizations', 'altimo' ); ?></span>
						<span class="action-value" id="stat-action-optimize">0</span>
					</div>
					<div class="action-stat">
						<span class="action-label"><?php esc_html_e( 'Manual Edits', 'altimo' ); ?></span>
						<span class="action-value" id="stat-action-manual">0</span>
					</div>
					<div class="action-stat">
						<span class="action-label"><?php esc_html_e( 'Regenerations', 'altimo' ); ?></span>
						<span class="action-value" id="stat-action-regenerate">0</span>
					</div>
					<div class="action-stat">
						<span class="action-label"><?php esc_html_e( 'Reverts', 'altimo' ); ?></span>
						<span class="action-value" id="stat-action-revert">0</span>
					</div>
					<div class="action-stat">
						<span class="action-label"><?php esc_html_e( 'Restores', 'altimo' ); ?></span>
						<span class="action-value" id="stat-action-restore">0</span>
					</div>
				</div>
			</div>

			<div class="stats-actions">
				<button type="button" class="button refresh-statistics">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh Statistics', 'altimo' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
