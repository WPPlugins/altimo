<?php
/**
 * View: Dashboard-Header (Header, Nav-Tabs, Credits-Info-Leiste).
 *
 * @var int    $remaining_credits Verbleibende Credits.
 * @var string $credit_warning    Badge to show: 'empty', 'low' or '' (Image_AI_SEO_Credit_Handler::get_warning_level()).
 *
 * @package Image_AI_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="image-ai-seo-header">
	<div class="logo-section">
		<?php // Brand mark of imageaiseo.com (resources/images/brand/logo-mark-light.svg in the service repo), a vector, so it stays crisp at every pixel density. ?>
		<img src="<?php echo esc_url( IMAGE_AI_SEO_PLUGIN_URL . 'assets/images/logo-mark.svg' ); ?>" alt="" class="logo" width="40" height="40">
		<h1><?php esc_html_e( 'Altimo', 'altimo' ); ?></h1>
	</div>
	<a href="https://imageaiseo.com" class="visit-website" target="_blank" rel="noopener noreferrer">
		<?php esc_html_e( 'Visit website', 'altimo' ); ?> ↗
	</a>
</div>
<?php // WordPress moves admin notices directly below this marker instead of into the header row. ?>
<hr class="wp-header-end">

<div class="nav-tab-wrapper" role="tablist" aria-label="<?php esc_attr_e( 'Altimo sections', 'altimo' ); ?>">
	<a href="#welcome" class="nav-tab nav-tab-active" role="tab" id="nav-tab-welcome" aria-controls="welcome" aria-selected="true" tabindex="0"><?php esc_html_e( 'Welcome', 'altimo' ); ?></a>
	<a href="#statistics" class="nav-tab" role="tab" id="nav-tab-statistics" aria-controls="statistics" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Statistics', 'altimo' ); ?></a>
	<a href="#bulk-optimization" class="nav-tab" role="tab" id="nav-tab-bulk-optimization" aria-controls="bulk-optimization" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Bulk optimization', 'altimo' ); ?></a>
	<a href="#history" class="nav-tab" role="tab" id="nav-tab-history" aria-controls="history" aria-selected="false" tabindex="-1"><?php esc_html_e( 'History', 'altimo' ); ?></a>
	<a href="#settings" class="nav-tab" role="tab" id="nav-tab-settings" aria-controls="settings" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Settings', 'altimo' ); ?></a>
	<a href="#license" class="nav-tab" role="tab" id="nav-tab-license" aria-controls="license" aria-selected="false" tabindex="-1"><?php esc_html_e( 'License', 'altimo' ); ?></a>
</div>

<div class="image-ai-seo-content">
	<div class="credits-info">
		<span class="remaining-credits" data-credits="<?php echo esc_attr( (string) $remaining_credits ); ?>">
			<?php esc_html_e( 'Remaining credits', 'altimo' ); ?>: <?php echo esc_html( (string) $remaining_credits ); ?>
		</span>
		<span class="credit-cost-info">
			<?php esc_html_e( '(1 credit per optimization)', 'altimo' ); ?>
		</span>
		<?php
		// D-05: both badges are always rendered and only the one matching the
		// balance is visible. admin-dashboard.js switches the same two elements
		// whenever it updates the count (key validation, optimization, bulk run,
		// history regenerate, balance refresh), so badge and count never disagree.
		?>
		<span class="credit-warning" data-credit-warning="low"<?php echo 'low' === $credit_warning ? '' : ' hidden'; ?>>
			<?php esc_html_e( 'Low credits!', 'altimo' ); ?>
		</span>
		<span class="credit-warning critical" data-credit-warning="empty"<?php echo 'empty' === $credit_warning ? '' : ' hidden'; ?>>
			<?php esc_html_e( 'No credits remaining!', 'altimo' ); ?>
		</span>
		<?php // D-06: buy-more-credits target is the pricing page of imageaiseo.com (route checked in the server project on 2026-10-06). ?>
		<a href="https://imageaiseo.com/pricing" class="button button-primary" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Buy more credits', 'altimo' ); ?>
		</a>
	</div>
