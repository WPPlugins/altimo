<?php
/**
 * View: Welcome-Tab.
 *
 * @var string $main_keyword        Gespeichertes Haupt-Fokus-Keyword.
 * @var string $additional_keywords Gespeicherte zusätzliche Keywords.
 * @var string $website_description Gespeicherte Website-Beschreibung.
 * @var bool   $site_profile_ready  Whether an API key and consent exist, so the
 *                                  AI suggestion can be requested (2.2.0).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="welcome" class="tab-content" role="tabpanel" aria-labelledby="nav-tab-welcome" tabindex="0">
	<div class="welcome-section">
		<h2><?php esc_html_e( 'Welcome to Altimo', 'altimo' ); ?></h2>
		<p><?php esc_html_e( 'Please provide your main focus keyword and a brief description of your website to help optimize your images.', 'altimo' ); ?></p>

		<div class="keyword-section">
			<?php // 2.2.0: AI suggestion of the three fields below. The request runs server-side (image_ai_seo_suggest_site_profile), the API key never reaches the browser. ?>
			<div class="image-ai-seo-site-profile">
				<p class="image-ai-seo-site-profile-intro">
					<?php esc_html_e( 'Let the AI suggest these fields from your site name, tagline, address, language and the titles of your recent posts, pages and products.', 'altimo' ); ?>
				</p>
				<button type="button" class="button suggest-site-profile" <?php disabled( ! $site_profile_ready ); ?>>
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<?php esc_html_e( 'Suggest with AI', 'altimo' ); ?>
				</button>
				<p class="description image-ai-seo-site-profile-hint"<?php echo $site_profile_ready ? ' hidden' : ''; ?>>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: opening link tag to the License tab, 2: closing link tag */
							__( 'To use AI suggestions, enter your API key and accept the data-processing notice on the %1$sLicense tab%2$s.', 'altimo' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=altimo&tab=license' ) ) . '" class="image-ai-seo-license-link">',
							'</a>'
						),
						array(
							'a' => array(
								'href'  => array(),
								'class' => array(),
							),
						)
					);
					?>
				</p>
				<div class="image-ai-seo-site-profile-status" role="status" aria-live="polite"></div>
			</div>

			<div class="input-group">
				<label>
					<h3><?php esc_html_e( 'Main Focus Keyword', 'altimo' ); ?></h3>
					<div class="input-wrapper">
						<input type="text"
								id="main-keyword"
								value="<?php echo esc_attr( $main_keyword ); ?>"
								class="regular-text"
								maxlength="50"
								placeholder="<?php esc_attr_e( 'Enter your main keyword', 'altimo' ); ?>">
						<span class="char-counter">0/50</span>
					</div>
				</label>
			</div>

			<div class="input-group">
				<label>
					<h3><?php esc_html_e( 'Additional Keywords', 'altimo' ); ?></h3>
					<div class="input-wrapper">
						<input type="text"
								id="additional-keywords"
								value="<?php echo esc_attr( $additional_keywords ); ?>"
								class="regular-text"
								maxlength="150"
								placeholder="<?php esc_attr_e( 'Enter additional keywords (optional)', 'altimo' ); ?>">
						<span class="char-counter">0/150</span>
					</div>
				</label>
			</div>

			<div class="input-group">
				<label>
					<h3><?php esc_html_e( 'Website Description', 'altimo' ); ?></h3>
					<div class="input-wrapper">
						<textarea
							id="website-description"
							class="regular-text"
							maxlength="1000"
							placeholder="<?php esc_attr_e( 'Enter a brief description of your website', 'altimo' ); ?>"
						><?php echo esc_textarea( $website_description ); ?></textarea>
						<span class="char-counter">0/1000</span>
					</div>
				</label>
			</div>

			<div class="setting-actions">
				<button type="button" class="button button-primary save-welcome-settings">
					<?php esc_html_e( 'Save Settings', 'altimo' ); ?>
				</button>
				<span class="settings-status"></span>
			</div>
		</div>
	</div>
</div>
