<?php

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet den Admin-Menüpunkt und rendert die Plugin-Seite.
 */
class Image_AI_SEO_Admin_Menu {

	/**
	 * Registriert den admin_menu-Hook.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
	}

	/**
	 * Fügt den Menüpunkt zum WordPress Admin-Menü hinzu
	 */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'Altimo', 'altimo' ),
			__( 'Altimo', 'altimo' ),
			'manage_options',
			'altimo',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-format-image',
			30
		);
	}

	/**
	 * Rendert die Admin-Seite
	 */
	public static function render_admin_page() {
		$api_key_data      = Image_AI_SEO_Api_Client::get_active_api_key();
		$api_key           = $api_key_data['key'];
		$api_key_scope     = $api_key_data['scope'];
		$consent_granted   = Image_AI_SEO_Consent_Handler::is_granted();
		$remaining_credits = (int) get_option( 'image_ai_seo_remaining_credits', 0 );
		$credit_warning    = Image_AI_SEO_Credit_Handler::get_warning_level( $remaining_credits, '' !== $api_key );
		$stats             = Image_AI_SEO_Metadata_Handler::get_image_optimization_stats();

		$main_keyword        = (string) get_option( 'image_ai_seo_main_keyword', '' );
		$additional_keywords = (string) get_option( 'image_ai_seo_additional_keywords', '' );
		$website_description = (string) get_option( 'image_ai_seo_website_description', '' );
		$site_profile_ready  = Image_AI_SEO_Site_Profile::can_request();

		$auto_optimize    = (bool) get_option( 'image_ai_seo_auto_optimize', false );
		$current_language = (string) get_option( 'image_ai_seo_language', 'en' );
		$languages        = array(
			'ar' => __( 'Arabic', 'altimo' ),
			'zh' => __( 'Chinese', 'altimo' ),
			'cs' => __( 'Czech', 'altimo' ),
			'da' => __( 'Danish', 'altimo' ),
			'nl' => __( 'Dutch', 'altimo' ),
			'en' => __( 'English', 'altimo' ),
			'fi' => __( 'Finnish', 'altimo' ),
			'fr' => __( 'French', 'altimo' ),
			'de' => __( 'German', 'altimo' ),
			'el' => __( 'Greek', 'altimo' ),
			'he' => __( 'Hebrew', 'altimo' ),
			'hi' => __( 'Hindi', 'altimo' ),
			'hu' => __( 'Hungarian', 'altimo' ),
			'id' => __( 'Indonesian', 'altimo' ),
			'it' => __( 'Italian', 'altimo' ),
			'ja' => __( 'Japanese', 'altimo' ),
			'ko' => __( 'Korean', 'altimo' ),
			'no' => __( 'Norwegian', 'altimo' ),
			'pl' => __( 'Polish', 'altimo' ),
			'pt' => __( 'Portuguese', 'altimo' ),
			'ro' => __( 'Romanian', 'altimo' ),
			'ru' => __( 'Russian', 'altimo' ),
			'es' => __( 'Spanish', 'altimo' ),
			'sv' => __( 'Swedish', 'altimo' ),
			'th' => __( 'Thai', 'altimo' ),
			'tr' => __( 'Turkish', 'altimo' ),
			'uk' => __( 'Ukrainian', 'altimo' ),
			'vi' => __( 'Vietnamese', 'altimo' ),
		);
		?>
		<div class="wrap image-ai-seo-dashboard">
			<?php
			require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/dashboard-header.php';
			require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/tab-welcome.php';
			require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/tab-statistics.php';
			require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/tab-bulk-optimization.php';
			require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/tab-history.php';
			require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/tab-settings.php';
			require IMAGE_AI_SEO_PLUGIN_DIR . 'includes/admin/views/tab-license.php';
			?>
			</div>
		</div>
		<?php
	}
}
