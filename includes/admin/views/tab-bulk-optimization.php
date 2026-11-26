<?php
/**
 * View: Bulk-Optimization-Tab.
 *
 * @var int   $remaining_credits Verbleibende Credits.
 * @var array $stats             Optimierungs-Statistiken (total/optimized/unoptimized).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="bulk-optimization" class="tab-content" role="tabpanel" aria-labelledby="nav-tab-bulk-optimization" tabindex="0">
	<div class="bulk-optimization-section">
		<h2><?php esc_html_e( 'Bulk Optimization', 'altimo' ); ?></h2>

		<div class="optimization-stats">
			<div class="stat-item">
				<span class="stat-label"><?php esc_html_e( 'Total Images', 'altimo' ); ?></span>
				<span class="stat-value"><?php echo esc_html( $stats['total'] ); ?></span>
			</div>
			<div class="stat-item">
				<span class="stat-label"><?php esc_html_e( 'Optimized', 'altimo' ); ?></span>
				<span class="stat-value optimized"><?php echo esc_html( $stats['optimized'] ); ?></span>
			</div>
			<div class="stat-item">
				<span class="stat-label"><?php esc_html_e( 'Not Optimized', 'altimo' ); ?></span>
				<span class="stat-value unoptimized"><?php echo esc_html( $stats['unoptimized'] ); ?></span>
			</div>
		</div>

		<div class="bulk-actions">
			<?php if ( $stats['unoptimized'] > 0 ) : ?>
				<div class="bulk-credit-info">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of images to optimize, 2: number of credits that will be used (same as %1$d), 3: number of credits currently available */
							__( 'Optimizing %1$d images will use %2$d credits. You have %3$d credits.', 'altimo' ),
							$stats['unoptimized'],
							$stats['unoptimized'],
							$remaining_credits
						)
					);
					?>
					<?php if ( $remaining_credits < $stats['unoptimized'] ) : ?>
						<br><span class="credit-insufficient">
							<?php esc_html_e( 'Warning: Not enough credits for all images!', 'altimo' ); ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( 0 === (int) $stats['unoptimized'] ) : ?>
			<button class="button button-primary start-bulk-optimization bulk-all-done" disabled>
				<?php esc_html_e( 'All images optimized', 'altimo' ); ?>
			</button>
			<?php else : ?>
			<button class="button button-primary start-bulk-optimization">
				<?php esc_html_e( 'Start Bulk Optimization', 'altimo' ); ?>
			</button>
			<?php endif; ?>
		</div>
	</div>
</div>
