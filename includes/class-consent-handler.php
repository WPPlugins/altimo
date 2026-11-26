<?php
/**
 * Consent-Speicherung und -Lese-API für GDPR-01 (Datenschutz-Einwilligung).
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Einziger Ort für Consent-Zustand: gewähren, widerrufen, lesen (GDPR-01/D-03/D-07/D-08).
 *
 * Vor jedem ausgehenden Request über Image_AI_SEO_Api_Client::post() steht die
 * Frage "hat der Nutzer zugestimmt?" -- diese Klasse ist die EINZIGE sanktionierte
 * Stelle, die diese Frage beantwortet oder den Zustand verändert. Ein zweiter,
 * unabhängiger Consent-Check an einer Aufrufstelle (und sei er noch so trivial)
 * ist genau das Fehlerbild, vor dem Pitfall 1 (06-RESEARCH.md) warnt.
 *
 * D-07: Widerruf löscht die Option NICHT, sondern kippt nur `granted` auf false
 * und setzt `revoked_at` -- ein Audit-Trail, keine Löschung.
 *
 * Pitfall 2 (06-RESEARCH.md): is_granted() liest ausschließlich mit einem
 * sicheren Default und darf niemals add_option()/update_option() als
 * Lese-Seiteneffekt auslösen. Die Option darf erst durch einen expliziten
 * grant()/revoke()-Aufruf entstehen -- niemals bei Aktivierung oder boot().
 * tests/integration/test-consent-site-scoping.php::test_no_consent_option_exists_yet()
 * ist der strukturelle Wächter dafür.
 *
 * D-08 (Phase 5): Consent gilt strikt pro Seite -- ausschließlich get_option()/
 * update_option(), niemals get_site_option()/update_site_option().
 */
class Image_AI_SEO_Consent_Handler {

	/**
	 * Name der seiten-eigenen Option, die den Consent-Zustand hält (D-08: nie
	 * get_site_option()).
	 */
	const OPTION_NAME = 'image_ai_seo_consent';

	/**
	 * Version des Consent-Textes, dem der Nutzer zugestimmt hat. Wird erhöht,
	 * wenn sich die Datenverarbeitungs-Beschreibung inhaltlich ändert
	 * (Pitfall 7, 06-RESEARCH.md: "re-prompt if the data-processing
	 * description materially changes").
	 *
	 * 2.0 (plugin 2.1.1): the disclosure now names the AI gateway FlowModels
	 * and the model vendor it forwards to, states the real retention period
	 * (request data is kept, then anonymised) instead of "no persistent
	 * storage", and lists the data sent for the balance and Pro update checks.
	 * A consent given to version 1.0 no longer counts, see is_granted().
	 *
	 * 3.0 (plugin 2.2.0): the disclosure adds the AI suggestion of the
	 * Welcome settings and the site data it sends (site name, tagline, website
	 * address, locale, output language, WooCommerce flag, recent titles). A
	 * consent given to version 2.0 has to be given again once.
	 *
	 * 3.1 (plugin 2.3.1): the sentence about the Pro update check is gone,
	 * because Image AI SEO Pro is discontinued and no update check exists any
	 * more. The version number is the only record of which text a site agreed
	 * to, so a consent given to version 3.0 has to be given again once.
	 */
	const TEXT_VERSION = '3.1';

	/**
	 * Prüft, ob auf dieser Seite aktuell Consent erteilt ist.
	 *
	 * Consent counts only if it was given to the CURRENT disclosure text
	 * (TEXT_VERSION). A consent given to an older text is treated as not
	 * granted, so every outbound request stays blocked until the site owner
	 * has read and accepted the changed text (see needs_reconsent()).
	 *
	 * Pitfall 2: liest ausschließlich, kein add_option()/update_option() als
	 * Seiteneffekt. Fehlt die Option (frische Installation), gilt sie als
	 * nicht erteilt.
	 *
	 * @return bool
	 */
	public static function is_granted() {
		$state = get_option( self::OPTION_NAME, array() );

		if ( empty( $state['granted'] ) ) {
			return false;
		}

		return isset( $state['text_version'] ) && self::TEXT_VERSION === (string) $state['text_version'];
	}

	/**
	 * Whether consent was granted on this site, but to an older version of the
	 * disclosure text, so it has to be asked for again.
	 *
	 * Read-only like is_granted() (Pitfall 2).
	 *
	 * @return bool
	 */
	public static function needs_reconsent() {
		$state = get_option( self::OPTION_NAME, array() );

		if ( empty( $state['granted'] ) ) {
			return false;
		}

		return ! isset( $state['text_version'] ) || self::TEXT_VERSION !== (string) $state['text_version'];
	}

	/**
	 * Erteilt Consent auf dieser Seite (D-01/D-03: im Moment der
	 * Schlüssel-Eingabe aufgerufen, bevor die Validierung selbst -- ein
	 * ausgehender Request -- stattfindet).
	 *
	 * @param int $user_id ID des Nutzers, der zugestimmt hat (Audit-Trail, D-07).
	 */
	public static function grant( $user_id ) {
		update_option(
			self::OPTION_NAME,
			array(
				'granted'      => true,
				'granted_at'   => time(),
				'revoked_at'   => null,
				'user_id'      => (int) $user_id,
				'text_version' => self::TEXT_VERSION,
			),
			// Kein Autoload -- die Option wird bei jedem Api_Client::post()-Aufruf
			// gelesen, aber nicht bei jedem Seitenaufruf gebraucht.
			false
		);
	}

	/**
	 * Widerruft Consent auf dieser Seite (D-06/D-07). Löscht die Option NICHT
	 * -- `granted_at` und `text_version` bleiben als Audit-Trail erhalten,
	 * nur `granted` wird auf false gesetzt und `revoked_at` neu gesetzt.
	 *
	 * @param int $user_id ID des Nutzers, der widerrufen hat (Audit-Trail, D-07).
	 */
	public static function revoke( $user_id ) {
		$state = get_option( self::OPTION_NAME, array() );

		$state['granted']    = false;
		$state['revoked_at'] = time();
		$state['user_id']    = (int) $user_id;

		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Liefert den vollständigen, rohen Consent-Zustand.
	 *
	 * @return array{granted?: bool, granted_at?: int, revoked_at?: int|null, user_id?: int, text_version?: string}
	 */
	public static function get_state() {
		return get_option( self::OPTION_NAME, array( 'granted' => false ) );
	}
}
