<?php

defined( 'ABSPATH' ) || exit;

/**
 * Netzwerk-Admin-Menüpunkt und Speicher-Handler für den Netzwerk-Schlüssel (D-01/D-03).
 *
 * Die WordPress Settings API funktioniert auf Netzwerk-Admin-Seiten nicht
 * (`register_setting()` bleibt dort wirkungslos, 05-RESEARCH.md Pattern 5).
 * Deshalb wird der POST hier von Hand über den `network_admin_edit_{$action}`-Hook
 * verarbeitet, analog zum bestehenden nonce-vor-capability-Muster aus
 * Image_AI_SEO_Settings_Handler.
 *
 * ASVS V4 (05-RESEARCH.md): `current_user_can( 'manage_network_options' )` muss
 * UNABHÄNGIG voneinander sowohl im Render- als auch im Speicher-Einstiegspunkt
 * geprüft werden -- `edit.php?action=...` ist ein eigener, direkt erreichbarer
 * Einstiegspunkt, den die Capability-Prüfung von add_menu_page() nicht abdeckt.
 */
class Image_AI_SEO_Network_Admin_Menu {

	/**
	 * Registriert die Netzwerk-Admin-Hooks.
	 */
	public static function init() {
		add_action( 'network_admin_menu', array( __CLASS__, 'add_network_menu' ) );
		add_action( 'network_admin_edit_image_ai_seo_save_network_key', array( __CLASS__, 'save_network_key' ) );
	}

	/**
	 * Fügt den Netzwerk-Admin-Menüpunkt hinzu.
	 */
	public static function add_network_menu() {
		add_menu_page(
			__( 'Altimo Network Settings', 'altimo' ),
			__( 'Altimo', 'altimo' ),
			'manage_network_options',
			'altimo-network',
			array( __CLASS__, 'render_network_page' ),
			'dashicons-format-image',
			30
		);
	}

	/**
	 * Rendert die Netzwerk-Einstellungsseite.
	 */
	public static function render_network_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'altimo' ) );
		}

		$network_api_key    = (string) get_site_option( 'image_ai_seo_network_api_key', '' );
		$masked_network_key = '';
		if ( ! empty( $network_api_key ) ) {
			$visible_chars      = 4;
			$masked_network_key = str_repeat( '•', max( 0, strlen( $network_api_key ) - $visible_chars ) )
				. substr( $network_api_key, -$visible_chars );
		}

		require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/network-settings.php';
	}

	/**
	 * Verarbeitet das Speichern des Netzwerk-Schlüssels.
	 *
	 * Nonce-Prüfung ZUERST (Projekt-Konvention, siehe
	 * Image_AI_SEO_Settings_Handler), DANACH die Capability-Prüfung -- ein
	 * eigener, unabhängiger Aufruf von der Render-Seite (ASVS V4).
	 */
	public static function save_network_key() {
		check_admin_referer( 'image_ai_seo_save_network_key' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'altimo' ) );
		}

		$key = isset( $_POST['network_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['network_api_key'] ) ) : '';

		if ( ! self::is_masked_placeholder( $key ) ) {
			update_site_option( 'image_ai_seo_network_api_key', $key );
		}

		wp_safe_redirect(
			add_query_arg( 'updated', 'true', network_admin_url( 'admin.php?page=altimo-network' ) )
		);
		exit;
	}

	/**
	 * Whether a submitted key is (or still contains) the masked placeholder shown in the form.
	 *
	 * The network form is pre-filled with the MASKED key (D-03) and, unlike the site-level
	 * license form, is a plain POST with no JS interception. Submitting it unchanged would
	 * otherwise persist a string of bullets over the real key and break every site on the
	 * network at once. Extracted from save_network_key() so it is reachable by tests, which
	 * cannot call that method directly because it ends in exit.
	 *
	 * @param string $key Submitted key.
	 * @return bool True when the value must not be persisted.
	 */
	public static function is_masked_placeholder( $key ) {
		return false !== strpos( $key, '•' );
	}
}
