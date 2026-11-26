<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- project convention: class-{descriptor}.php, like every class file here.
/**
 * The Plugins-screen notice that tells the admin what to do with an old copy
 * of the plugin (Image AI SEO or Image AI SEO Pro) next to Altimo.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registered by Image_AI_SEO_Variant_Guard whenever a copy stays dormant, and
 * by altimo.php in place of the notice of an older guard that loaded first.
 *
 * The advice depends on the old copy, read from its header when the notice
 * is rendered:
 *
 * - Image AI SEO 2.3.3 or newer keeps the shared data on uninstall while
 *   Altimo is installed: deactivate and delete it.
 * - Image AI SEO older than 2.3.3 does not know Altimo and deletes the shared
 *   API key and settings on uninstall: deactivate it, and delete it only after
 *   updating it to 2.3.3.
 * - Image AI SEO Pro (discontinued, never newer than 2.3.0) deletes them too
 *   and cannot be updated: deactivate it and remove its folder instead of
 *   deleting it on the Plugins screen.
 *
 * When Altimo itself is paused (an old copy older than 2.3.0 has no guard, or
 * an old copy loaded first), the notice says so. It appears only on the
 * Plugins screen of a site and of the network admin, for users who can manage
 * plugins, and cannot be dismissed while the old copy is active.
 *
 * Like the guard, this file can be required by several copies in the same
 * request, so it is only loaded when the class does not exist yet; it reads
 * the active plugins on its own and works next to any version of the guard.
 */
class Image_AI_SEO_Variant_Notice {

	/**
	 * Admin screens that show the notice: the Plugins screen of a site and of
	 * the network admin (wordpress.org guideline 11, no notice on every screen).
	 */
	const SCREEN_IDS = array( 'plugins', 'plugins-network' );

	/**
	 * Main file name of every old copy (Image AI SEO and Image AI SEO Pro).
	 */
	const LEGACY_MAIN_FILE = 'image-ai-seo.php';

	/**
	 * Folder of the discontinued Image AI SEO Pro.
	 */
	const PRO_FOLDER = 'image-ai-seo-pro';

	/**
	 * First old version whose uninstall keeps the shared data while Altimo is installed.
	 */
	const SAFE_UNINSTALL_SINCE_VERSION = '2.3.3';

	/**
	 * Where the old Image AI SEO 2.3.3 can be downloaded.
	 */
	const DOWNLOAD_URL = 'https://imageaiseo.com/download';

	/**
	 * Whether a notice is registered in this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Whether Altimo itself stays dormant in this request.
	 *
	 * @var bool
	 */
	private static $altimo_paused = false;

	/**
	 * Registers the notice on the admin and network admin notice hooks.
	 *
	 * @param bool $altimo_paused Whether Altimo itself stays dormant.
	 * @return void
	 */
	public static function register( $altimo_paused ) {
		self::$registered    = true;
		self::$altimo_paused = self::$altimo_paused || (bool) $altimo_paused;

		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * Replaces the notice of an older guard that an old copy brought along.
	 *
	 * An old copy that loads before Altimo (on a network, when it was
	 * network-activated first) defines the guard class with its own, older
	 * code, whose notice does not know Altimo (2.3.0 to 2.3.2) or knows only
	 * its own side (2.3.3). Altimo then unhooks that notice and shows this one.
	 * Does nothing when the guard in memory is Altimo's own.
	 *
	 * @param bool $altimo_paused Whether Altimo stays dormant in this request.
	 * @return void
	 */
	public static function replace_older_guard_notice( $altimo_paused ) {
		if ( defined( 'Image_AI_SEO_Variant_Guard::NOTICE_CLASS' ) ) {
			return;
		}

		$older_notice = array( 'Image_AI_SEO_Variant_Guard', 'render_notice' );

		if ( ! $altimo_paused && false === has_action( 'admin_notices', $older_notice ) ) {
			return;
		}

		remove_action( 'admin_notices', $older_notice );
		remove_action( 'network_admin_notices', $older_notice );
		self::register( $altimo_paused );
	}

	/**
	 * Whether a notice is registered in this request.
	 *
	 * @return bool
	 */
	public static function is_registered() {
		return self::$registered;
	}

	/**
	 * Whether Altimo itself stays dormant in this request.
	 *
	 * @return bool
	 */
	public static function is_altimo_paused() {
		return self::$altimo_paused;
	}

	/**
	 * Forgets the registered notice (for tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$registered    = false;
		self::$altimo_paused = false;

		remove_action( 'admin_notices', array( __CLASS__, 'render' ) );
		remove_action( 'network_admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * Active old copies of the plugin, site-wide and network-wide.
	 *
	 * An old copy is an active plugin whose main file is `image-ai-seo.php`
	 * and is on disk; it is Image AI SEO Pro when it lives in the Pro folder.
	 *
	 * @return array<int, array{basename: string, version: string, is_pro: bool}>
	 */
	public static function get_active_legacy_copies() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$copies = array();

		foreach ( array_unique( array_map( 'strval', $active ) ) as $basename ) {
			$file = WP_PLUGIN_DIR . '/' . $basename;

			if ( self::LEGACY_MAIN_FILE !== basename( $basename ) || ! is_file( $file ) ) {
				continue;
			}

			$headers  = get_file_data( $file, array( 'Version' => 'Version' ) );
			$copies[] = array(
				'basename' => $basename,
				'version'  => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
				'is_pro'   => self::PRO_FOLDER === dirname( $basename ),
			);
		}

		return $copies;
	}

	/**
	 * The advice for one old copy.
	 *
	 * @param array{basename: string, version: string, is_pro: bool} $copy          An old copy.
	 * @param bool                                                   $altimo_paused Whether Altimo stays dormant.
	 * @return string Translated text.
	 */
	public static function get_copy_message( array $copy, $altimo_paused ) {
		if ( $copy['is_pro'] ) {
			return $altimo_paused
				? __( 'Altimo is paused until you deactivate Image AI SEO Pro. Do not delete Image AI SEO Pro on the Plugins screen, because that deletes the shared API key and settings; remove its folder by FTP instead.', 'altimo' )
				: __( 'Image AI SEO Pro is discontinued and replaced by Altimo. Deactivate Image AI SEO Pro, but do not delete it on the Plugins screen, because that deletes the shared API key and settings; remove its folder by FTP instead.', 'altimo' );
		}

		$deletes_data = '' === $copy['version'] || version_compare( $copy['version'], self::SAFE_UNINSTALL_SINCE_VERSION, '<' );

		if ( $deletes_data ) {
			return $altimo_paused
				? sprintf(
					/* translators: %s: download URL of Image AI SEO 2.3.3 */
					__( 'Altimo is paused until you deactivate the old Image AI SEO plugin. Do not delete it before you have updated it to version 2.3.3 (download at %s): older versions delete the shared API key and settings on uninstall.', 'altimo' ),
					self::DOWNLOAD_URL
				)
				: sprintf(
					/* translators: %s: download URL of Image AI SEO 2.3.3 */
					__( 'Image AI SEO is now Altimo. Deactivate the old Image AI SEO plugin, but do not delete it before you have updated it to version 2.3.3 (download at %s): older versions delete the shared API key and settings on uninstall.', 'altimo' ),
					self::DOWNLOAD_URL
				);
		}

		return $altimo_paused
			? __( 'Altimo is paused until you deactivate the old Image AI SEO plugin. Then delete it; your API key and settings stay.', 'altimo' )
			: __( 'Image AI SEO is now Altimo. Deactivate and delete the old Image AI SEO plugin; your API key and settings stay.', 'altimo' );
	}

	/**
	 * Every paragraph of the notice, one per active old copy.
	 *
	 * Without an old copy, a paused Altimo means a second copy of Altimo is
	 * active; a dormant old copy that is not active yet (it is just being
	 * activated) needs no notice.
	 *
	 * @return string[] Translated texts, empty when there is nothing to say.
	 */
	public static function get_messages() {
		$messages = array();

		foreach ( self::get_active_legacy_copies() as $copy ) {
			$messages[] = self::get_copy_message( $copy, self::$altimo_paused );
		}

		if ( empty( $messages ) && self::$altimo_paused ) {
			$messages[] = __( 'Altimo is paused because another copy of Altimo is active. Deactivate and delete one of the two copies; your API key and settings stay.', 'altimo' );
		}

		return array_values( array_unique( $messages ) );
	}

	/**
	 * Whether the current admin screen is the Plugins screen of the site or
	 * of the network admin, the only place the notice is shown.
	 *
	 * @return bool
	 */
	public static function is_notice_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return is_object( $screen ) && in_array( $screen->id, self::SCREEN_IDS, true );
	}

	/**
	 * Prints the notice: only for users who can act on it, only on the Plugins
	 * screen (wordpress.org guideline 11), not dismissible, because the old
	 * copy stays a problem until it is deactivated.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::$registered || ! self::is_notice_screen() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$messages = self::get_messages();
		if ( empty( $messages ) ) {
			return;
		}

		printf(
			'<div class="notice %s image-ai-seo-variant-notice">',
			esc_attr( self::$altimo_paused ? 'notice-error' : 'notice-warning' )
		);
		foreach ( $messages as $message ) {
			printf( '<p>%s</p>', wp_kses( self::link_download_url( esc_html( $message ) ), array( 'a' => array( 'href' => array() ) ) ) );
		}
		echo '</div>';
	}

	/**
	 * Turns the download address in an escaped notice paragraph into a link.
	 *
	 * The messages stay plain text (the address fills a %s placeholder), so
	 * translations keep working unchanged; only the rendered paragraph links it.
	 *
	 * @param string $escaped_message Message already passed through esc_html().
	 * @return string Paragraph content with the address as an anchor.
	 */
	private static function link_download_url( $escaped_message ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::DOWNLOAD_URL ),
			esc_html( self::DOWNLOAD_URL )
		);

		return str_replace( esc_html( self::DOWNLOAD_URL ), $link, $escaped_message );
	}
}
