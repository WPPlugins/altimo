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
					echo esc_html( Image_AI_SEO_Credit_Handler::get_bulk_credit_info( $stats['unoptimized'], $remaining_credits ) );
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
