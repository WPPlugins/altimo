<?php
/**
 * View: License-Tab.
 *
 * @var string $api_key       Der gespeicherte (unmaskierte) API-Key. Wird in dieser
 *                             View NIE direkt ausgegeben, sondern nur maskiert
 *                             (QUAL-08, D-08): alle Zeichen außer den letzten 4
 *                             werden durch `•` ersetzt.
 * @var string $api_key_scope 'site', 'network' oder 'none' (D-01/D-03) --
 *                             welche Ebene den aktiven Schlüssel liefert.
 * @var bool   $consent_granted Ob auf dieser Seite bereits Consent erteilt
 *                             wurde (GDPR-02/D-06/D-07) -- steuert, ob die
 *                             Checkbox vorausgehakt ist und ob der
 *                             "Revoke consent"-Button angezeigt wird.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$masked_api_key = '';
if ( ! empty( $api_key ) ) {
	$visible_chars  = 4;
	$masked_api_key = str_repeat( '•', max( 0, strlen( $api_key ) - $visible_chars ) )
		. substr( $api_key, -$visible_chars );
}
?>
<div id="license" class="tab-content" role="tabpanel" aria-labelledby="nav-tab-license" tabindex="0">
	<div class="license-section">
		<?php if ( empty( $api_key ) ) : ?>
		<!-- Nicht aktiviert - Ansprechende Aktivierungsseite -->
		<div class="license-hero">
			<div class="license-hero-icon">
				<span class="dashicons dashicons-unlock"></span>
			</div>
			<h2><?php esc_html_e( 'Activate Altimo', 'altimo' ); ?></h2>
			<p class="license-hero-subtitle"><?php esc_html_e( 'Unlock the full power of AI-driven image optimization for your WordPress site.', 'altimo' ); ?></p>
		</div>

		<div class="license-features">
			<div class="license-feature">
				<span class="dashicons dashicons-yes-alt"></span>
				<div>
					<strong><?php esc_html_e( 'Free starter credits', 'altimo' ); ?></strong>
					<span><?php esc_html_e( 'Start optimizing immediately after registration', 'altimo' ); ?></span>
				</div>
			</div>
			<div class="license-feature">
				<span class="dashicons dashicons-yes-alt"></span>
				<div>
					<strong><?php esc_html_e( 'AI-Powered Optimization', 'altimo' ); ?></strong>
					<span><?php esc_html_e( 'Smart titles, alt texts & filenames', 'altimo' ); ?></span>
				</div>
			</div>
			<div class="license-feature">
				<span class="dashicons dashicons-yes-alt"></span>
				<div>
					<strong><?php esc_html_e( 'Improve Your SEO', 'altimo' ); ?></strong>
					<span><?php esc_html_e( 'Better rankings through optimized images', 'altimo' ); ?></span>
				</div>
			</div>
		</div>

		<div class="license-cta-box">
			<h3><?php esc_html_e( 'Don\'t have an API key yet?', 'altimo' ); ?></h3>
			<p><?php esc_html_e( 'Create a free account and get free starter credits to try Altimo. No credit card required!', 'altimo' ); ?></p>
			<a href="https://imageaiseo.com/register" target="_blank" rel="noopener noreferrer" class="button button-hero">
				<?php esc_html_e( 'Get Your Free API Key', 'altimo' ); ?>
				<span class="dashicons dashicons-external"></span>
			</a>
		</div>

		<div class="license-divider">
			<span><?php esc_html_e( 'Already have an API key?', 'altimo' ); ?></span>
		</div>
		<?php else : ?>
		<!-- Bereits aktiviert -->
		<div class="license-hero license-hero-active">
			<div class="license-hero-icon license-hero-icon-active">
				<span class="dashicons dashicons-yes"></span>
			</div>
			<h2><?php esc_html_e( 'License Active', 'altimo' ); ?></h2>
			<p class="license-hero-subtitle"><?php esc_html_e( 'Your Altimo license is activated and ready to use.', 'altimo' ); ?></p>
		</div>
		<?php endif; ?>

		<div class="api-key-section <?php echo empty( $api_key ) ? '' : 'api-key-active'; ?>">
			<h3><label for="image-ai-seo-api-key"><?php esc_html_e( 'Enter Your API Key', 'altimo' ); ?></label></h3>
			<div class="api-key-input-wrapper">
				<input type="text"
					id="image-ai-seo-api-key"
					value="<?php echo empty( $api_key ) ? '' : esc_attr( $masked_api_key ); ?>"
					data-has-key="<?php echo empty( $api_key ) ? '0' : '1'; ?>"
					class="api-key-input"
					placeholder="<?php esc_attr_e( 'Paste your API key here...', 'altimo' ); ?>">
				<button class="button button-primary validate-key">
					<span class="dashicons dashicons-yes"></span>
					<?php esc_html_e( 'Validate & Save', 'altimo' ); ?>
				</button>
			</div>

			<div class="consent-section">
				<?php if ( ! $consent_granted && Image_AI_SEO_Consent_Handler::needs_reconsent() ) : ?>
				<div class="notice notice-warning inline image-ai-seo-reconsent-hint">
					<p>
						<strong><?php esc_html_e( 'The data-processing notice has changed.', 'altimo' ); ?></strong>
						<?php esc_html_e( 'Please read the updated notice below, tick the checkbox and click "Validate & Save" to continue optimizing images and using AI suggestions. Your saved API key is kept.', 'altimo' ); ?>
					</p>
				</div>
				<?php elseif ( ! $consent_granted && ! empty( $api_key ) ) : ?>
				<p class="description image-ai-seo-consent-hint">
					<?php esc_html_e( 'Consent is required before images can be optimized. Tick the checkbox and click "Validate & Save". Your saved API key is kept.', 'altimo' ); ?>
				</p>
				<?php endif; ?>
				<label for="image-ai-seo-consent-checkbox">
					<input type="checkbox"
						id="image-ai-seo-consent-checkbox"
						class="consent-checkbox"
						<?php echo $consent_granted ? 'checked' : ''; ?>>
					<?php echo esc_html( Image_AI_SEO_Consent_Disclosure::get_summary_text() ); ?>
				</label>
				<details class="consent-details">
					<summary><?php esc_html_e( 'What exactly is transmitted?', 'altimo' ); ?></summary>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_detail_text() already runs its assembled HTML through wp_kses_post() internally (see class-consent-disclosure.php), the sniff cannot see through the method call.
					echo Image_AI_SEO_Consent_Disclosure::get_detail_text();
					?>
				</details>
				<?php if ( $consent_granted ) : ?>
				<button type="button" class="button-link revoke-consent">
					<?php esc_html_e( 'Revoke consent', 'altimo' ); ?>
				</button>
				<?php endif; ?>
			</div>

			<span class="validation-status"></span>
			<?php if ( 'network' === $api_key_scope ) : ?><p class="description"><?php esc_html_e( 'Provided by your network administrator.', 'altimo' ); ?></p><?php endif; ?>
		</div>

		<?php if ( ! empty( $api_key ) ) : ?>
		<div class="license-info-box">
			<div class="license-info-item">
				<span class="dashicons dashicons-admin-network"></span>
				<div>
					<strong><?php esc_html_e( 'Need more credits?', 'altimo' ); ?></strong>
					<a href="https://imageaiseo.com/pricing" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Purchase additional credits', 'altimo' ); ?></a>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>
</div>
