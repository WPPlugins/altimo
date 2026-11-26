<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- project convention: class-{descriptor}.php, like every class file here.
/**
 * Keeps Altimo – AI Image SEO and the old copies of the plugin it replaces
 * (Image AI SEO and the discontinued Image AI SEO Pro) from loading in the
 * same request.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Altimo (folder `altimo`, main file `altimo.php`, 3.0.0) is the renamed
 * Image AI SEO. It is built from the same source as the old copies: they all
 * declare the same constants, functions and classes and share the same
 * options and tables, which is how Altimo takes over the API key, the
 * settings and the history of an existing install. When two copies are
 * active, the second one to load would end the request with "Cannot declare
 * class". Every main file asks this guard first, and when the guard says so,
 * it returns before it defines or requires anything else.
 *
 * The rules, seen from the copy that asks:
 *
 * - Any copy stays dormant when another copy already loaded in this request.
 * - Any copy stays dormant while an active old copy older than 2.3.0 (which
 *   has no guard and would fatally redeclare the classes when it loads
 *   second) is still to come. That is the only way Altimo itself steps aside.
 * - An old copy stays dormant while Altimo is active, whichever loads first.
 *   On a single site Altimo loads first anyway (`altimo/…` sorts before
 *   `image-ai-seo/…`), so an old 2.3.0+ copy calls this class, the one of
 *   Altimo, and goes dormant here.
 *
 * The notice that explains what to do is Image_AI_SEO_Variant_Notice; it is
 * shown only on the Plugins screen.
 *
 * This file can be required by several copies in the same request, so the
 * main file only loads it when the class does not exist yet. An old copy that
 * loaded first brings its own, older version of this class; altimo.php then
 * replaces that version's notice (Image_AI_SEO_Variant_Notice::replace_older_guard_notice()).
 * Everything here is static and has no side effect until a method is called.
 */
class Image_AI_SEO_Variant_Guard {

	/**
	 * Plugin basename of Altimo – AI Image SEO.
	 */
	const ALTIMO_BASENAME = 'altimo/altimo.php';

	/**
	 * File name of Altimo's main file, also in a folder with another name.
	 */
	const ALTIMO_MAIN_FILE = 'altimo.php';

	/**
	 * Plugin basename of the old Image AI SEO (before the rename to Altimo).
	 */
	const FREE_BASENAME = 'image-ai-seo/image-ai-seo.php';

	/**
	 * Plugin basename of the discontinued Image AI SEO Pro.
	 */
	const PRO_BASENAME = 'image-ai-seo-pro/image-ai-seo.php';

	/**
	 * First version that ships this guard. An older copy has no guard and
	 * would end the request when it loads second.
	 */
	const GUARD_SINCE_VERSION = '2.3.0';

	/**
	 * Class that shows the notice of this guard. Its presence also tells
	 * altimo.php that the guard in memory is Altimo's own, not an older one.
	 */
	const NOTICE_CLASS = 'Image_AI_SEO_Variant_Notice';

	/**
	 * Edition of the copy that went dormant in this request ('altimo', 'free'
	 * for the old Image AI SEO, 'pro' for Image AI SEO Pro), empty while every
	 * copy may load.
	 *
	 * @var string
	 */
	private static $dormant_edition = '';

	/**
	 * Whether the copy in $plugin_file must stay dormant in this request.
	 *
	 * See the class docblock for the rules. In every dormant case the notice
	 * is registered here.
	 *
	 * @param string $plugin_file Absolute path of the main file that asks.
	 * @param string $edition     Edition the main file passes: 'free' (Altimo
	 *                            and the old Image AI SEO) or 'pro' (the
	 *                            discontinued Image AI SEO Pro).
	 * @return bool
	 */
	public static function should_stay_dormant( $plugin_file, $edition ) {
		$is_altimo = self::is_altimo_file( $plugin_file );

		$is_dormant = self::is_other_copy_loaded()
			|| '' !== self::get_pending_legacy_sibling( $plugin_file )
			|| ( ! $is_altimo && self::is_altimo_active() );

		if ( $is_dormant ) {
			self::register_notice( $is_altimo ? 'altimo' : $edition );
		}

		return $is_dormant;
	}

	/**
	 * Whether the given main file is the one of Altimo (`altimo.php`, in any folder).
	 *
	 * @param string $plugin_file Absolute path of a main file.
	 * @return bool
	 */
	public static function is_altimo_file( $plugin_file ) {
		return self::ALTIMO_MAIN_FILE === basename( str_replace( '\\', '/', (string) $plugin_file ) );
	}

	/**
	 * Whether Altimo is active on this site (network-wide included) and its
	 * main file is on disk.
	 *
	 * @return bool
	 */
	public static function is_altimo_active() {
		foreach ( self::get_active_basenames() as $basename ) {
			if ( self::is_altimo_file( $basename ) && is_file( WP_PLUGIN_DIR . '/' . $basename ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a copy of the plugin, of any version, already loaded in this request.
	 *
	 * @return bool
	 */
	public static function is_other_copy_loaded() {
		return defined( 'IMAGE_AI_SEO_VERSION' ) || class_exists( 'Image_AI_SEO_Plugin', false );
	}

	/**
	 * Basenames of the old copies that could be active next to the given main file.
	 *
	 * Altimo is never a sibling here: it carries this guard and steps aside on
	 * its own. A copy in a folder with another name (a manual upload) treats
	 * both old copies as siblings.
	 *
	 * @param string $plugin_file Absolute path of the main file that asks.
	 * @return string[]
	 */
	public static function get_sibling_basenames( $plugin_file ) {
		$own_basename = plugin_basename( $plugin_file );

		return array_values(
			array_filter(
				array( self::FREE_BASENAME, self::PRO_BASENAME ),
				static function ( $basename ) use ( $own_basename ) {
					return $basename !== $own_basename;
				}
			)
		);
	}

	/**
	 * Basenames of every plugin active on this site, network-wide ones included.
	 *
	 * @return string[]
	 */
	public static function get_active_basenames() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return array_values( array_unique( array_map( 'strval', $active ) ) );
	}

	/**
	 * The active old copy that has not loaded yet and is older than this guard.
	 *
	 * @param string $plugin_file Absolute path of the main file that asks.
	 * @return string Its basename, or '' when there is none.
	 */
	public static function get_pending_legacy_sibling( $plugin_file ) {
		$active = self::get_active_basenames();

		foreach ( self::get_sibling_basenames( $plugin_file ) as $basename ) {
			if ( ! in_array( $basename, $active, true ) ) {
				continue;
			}

			$version = self::get_installed_version( $basename );

			if ( '' !== $version && version_compare( $version, self::GUARD_SINCE_VERSION, '<' ) ) {
				return $basename;
			}
		}

		return '';
	}

	/**
	 * Version header of an installed plugin.
	 *
	 * @param string $basename Plugin basename.
	 * @return string The version, '' when the file is missing or has none.
	 */
	public static function get_installed_version( $basename ) {
		$file = WP_PLUGIN_DIR . '/' . $basename;
		if ( ! is_file( $file ) ) {
			return '';
		}

		$headers = get_file_data( $file, array( 'Version' => 'Version' ) );

		return isset( $headers['Version'] ) ? (string) $headers['Version'] : '';
	}

	/**
	 * Remembers the dormant copy and registers the notice.
	 *
	 * @param string $edition 'altimo', 'free' or 'pro', the dormant copy.
	 * @return void
	 */
	public static function register_notice( $edition ) {
		// When Altimo itself is paused, that is what the request is about.
		if ( 'altimo' !== self::$dormant_edition ) {
			self::$dormant_edition = in_array( $edition, array( 'altimo', 'pro' ), true ) ? $edition : 'free';
		}

		if ( ! class_exists( self::NOTICE_CLASS, false ) ) {
			require_once __DIR__ . '/class-variant-notice.php';
		}

		Image_AI_SEO_Variant_Notice::register( 'altimo' === self::$dormant_edition );
	}

	/**
	 * Edition of the dormant copy in this request ('altimo', 'free' or
	 * 'pro'), '' when there is none.
	 *
	 * @return string
	 */
	public static function get_dormant_edition() {
		return self::$dormant_edition;
	}
}
