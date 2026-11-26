<?php
/**
 * View: Settings-Tab.
 *
 * @var bool   $auto_optimize     Ob die automatische Optimierung beim Upload aktiviert ist.
 * @var string $current_language  Aktuell gewählte KI-Ausgabesprache.
 * @var array  $languages         Sprachcode => Sprachname-Zuordnung.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="settings" class="tab-content" role="tabpanel" aria-labelledby="nav-tab-settings" tabindex="0">
	<div class="settings-section">
		<h2><?php esc_html_e( 'Settings', 'altimo' ); ?></h2>

		<div class="settings-form">
			<div class="setting-group">
				<h3><?php esc_html_e( 'General Settings', 'altimo' ); ?></h3>
				<label>
					<input type="checkbox"
							name="auto_optimize"
							value="1"
							<?php checked( $auto_optimize, true ); ?>>
					<?php esc_html_e( 'Automatically optimize images on upload', 'altimo' ); ?>
				</label>
			</div>

			<div class="setting-group">
				<h3><?php esc_html_e( 'Language Settings', 'altimo' ); ?></h3>
				<label>
					<select name="ai_language">
						<?php
						foreach ( $languages as $code => $name ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $code ),
								selected( $current_language, $code, false ),
								esc_html( $name )
							);
						}
						?>
					</select>
					<?php esc_html_e( 'AI Output Language', 'altimo' ); ?>
				</label>
			</div>

			<div class="setting-actions">
				<button type="button" class="button button-primary save-settings">
					<?php esc_html_e( 'Save Settings', 'altimo' ); ?>
				</button>
				<span class="settings-status"></span>
			</div>
		</div>
	</div>
</div>
