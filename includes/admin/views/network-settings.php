<?php
/**
 * View: Netzwerk-Einstellungsseite (D-01, D-03).
 *
 * @var string $network_api_key    Der gespeicherte (unmaskierte) Netzwerk-Key.
 *                                  Wird in dieser View NIE direkt ausgegeben.
 * @var string $masked_network_key Der maskierte Key (nur letzte 4 Zeichen sichtbar).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap image-ai-seo-dashboard">
	<h1><?php esc_html_e( 'Altimo: Network Settings', 'altimo' ); ?></h1>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect after a nonce-checked save. ?>
	<?php if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Network API key saved.', 'altimo' ); ?></p>
		</div>
	<?php endif; ?>

	<p>
		<?php esc_html_e( 'Set an API key here to share it across every site in this network. Individual sites may still set their own key, which then takes precedence on that site.', 'altimo' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=image_ai_seo_save_network_key' ) ); ?>">
		<?php wp_nonce_field( 'image_ai_seo_save_network_key' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="image-ai-seo-network-api-key"><?php esc_html_e( 'Network API Key', 'altimo' ); ?></label>
				</th>
				<td>
					<input type="text"
						id="image-ai-seo-network-api-key"
						name="network_api_key"
						value="<?php echo empty( $network_api_key ) ? '' : esc_attr( $masked_network_key ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Paste your API key here...', 'altimo' ); ?>">
					<p class="description">
						<?php esc_html_e( 'Leave unchanged to keep the currently saved key.', 'altimo' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Network Key', 'altimo' ) ); ?>
	</form>
</div>
