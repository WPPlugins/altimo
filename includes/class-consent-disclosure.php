<?php
/**
 * Central legal-text source for the D-04 GDPR consent disclosure.
 *
 * @package Image_AI_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single central place for every D-04 legal fact (data categories, recipient,
 * purpose, retention) shown to the user in the consent checkbox/<details>
 * disclosure (GDPR-02/D-04/D-05). A future update to any of these facts
 * touches exactly this one file. Phase 9 (DIST-04) reuses this same text for
 * the readme "External services" section, so the wording here is the single
 * source of truth for both surfaces.
 *
 * Every fact mirrors the published privacy policy of the service
 * (https://imageaiseo.com/privacy, checked 2026-11-18): the transfer basis
 * (adequacy decision for Brazil, SCC as an additional safeguard), the AI
 * gateway FlowModels operated by the recipient itself, and the retention
 * period after which request records are anonymised. No AI model vendor is
 * named in code (D-10); the privacy policy is the single source of truth for
 * that name. Changing any of these facts materially requires a new
 * Image_AI_SEO_Consent_Handler::TEXT_VERSION, so existing consents are asked
 * for again.
 */
class Image_AI_SEO_Consent_Disclosure {

	/**
	 * Whether every D-04 legal fact has been confirmed by the user (see
	 * .planning/phases/06-dsgvo-consent/LEGAL-FACTS.md, confirmed 2026-07-22).
	 * true means the disclosure below carries no placeholder text.
	 */
	const LEGAL_FACTS_COMPLETE = true;

	/**
	 * Recipient of the transmitted data (LEGAL-FACTS.md, confirmed verbatim).
	 */
	const COMPANY_NAME = 'Creative Skyline Ltda.';

	/**
	 * Registered office of the recipient (LEGAL-FACTS.md, confirmed verbatim).
	 */
	const COMPANY_ADDRESS = 'Avenida Washington Soares 55, 60811-341 Fortaleza, Brazil';

	/**
	 * Country the data is transferred to -- a third country outside the EU
	 * under GDPR Art. 44 ff. (LEGAL-FACTS.md).
	 */
	const COMPANY_COUNTRY = 'Brazil';

	/**
	 * Name of the AI gateway the service runs every AI request through. It is
	 * operated by the recipient itself and forwards each request to the AI
	 * model vendor named in the privacy policy (owner decision 2026-11-18).
	 */
	const AI_GATEWAY_NAME = 'FlowModels';

	/**
	 * Days after which the service anonymises the personal details of every
	 * processing record and registered site. Mirrors the server's
	 * `IMAGEAISEO_RETENTION_DAYS` default (config/custom/imageaiseo.php) and
	 * the live privacy policy, section 7 (checked 2026-11-18).
	 */
	const RETENTION_DAYS = 90;

	/**
	 * Privacy-policy URL of the recipient's service. Taken from the legal pages of the server project (English at the site root, identical in every language), checked 2026-10-06; identical to the External Services entry in readme.txt.
	 */
	const PRIVACY_POLICY_URL = 'https://imageaiseo.com/privacy';

	/**
	 * Terms-of-service URL of the recipient's service (readme External Services).
	 */
	const TERMS_URL = 'https://imageaiseo.com/terms';

	/**
	 * Names the five data categories Image_AI_SEO_Image_Optimizer::generate()
	 * actually transmits (D-04: no pauschal wording like "technisch
	 * notwendige Daten" -- every category is named concretely).
	 *
	 * @return string[] Five translated data-category names.
	 */
	public static function get_optimize_data_categories() {
		return array(
			__( 'Image URL', 'altimo' ),
			__( 'Focus keyword', 'altimo' ),
			__( 'Additional keywords', 'altimo' ),
			__( 'Website description', 'altimo' ),
			__( 'Language', 'altimo' ),
		);
	}

	/**
	 * Names the three additional data categories
	 * Image_AI_SEO_API_Validator::validate_api_key() actually transmits --
	 * more than a user expects from a plain "key check" (06-CONTEXT.md's
	 * "Spezifika"), so this must be disclosed separately from the optimize
	 * categories above. The same request is used for the credit balance check.
	 *
	 * @return string[] Three translated data-category names.
	 */
	public static function get_validation_data_categories() {
		return array(
			__( 'Website domain', 'altimo' ),
			__( 'Server IP address', 'altimo' ),
			__( 'Plugin version', 'altimo' ),
		);
	}

	/**
	 * Names the data categories Image_AI_SEO_Site_Profile::build_request_body()
	 * transmits when AI suggestions for the Welcome settings are requested
	 * (2.2.0). One entry per JSON key sent, together with the API key.
	 *
	 * @return string[] Seven translated data-category names.
	 */
	public static function get_site_profile_data_categories() {
		return array(
			__( 'Site name', 'altimo' ),
			__( 'Site tagline', 'altimo' ),
			__( 'Website address', 'altimo' ),
			__( 'WordPress language (locale)', 'altimo' ),
			__( 'Output language', 'altimo' ),
			__( 'Whether WooCommerce is active', 'altimo' ),
			__( 'Titles of up to 20 recently published posts, pages and products', 'altimo' ),
		);
	}

	/**
	 * Explains what the service does with the image URL and which data
	 * travels with every request regardless of the endpoint.
	 *
	 * @return string Translated text.
	 */
	public static function get_image_handling_text() {
		return __(
			'The service downloads the image from its URL, reduces its size and passes it to the AI model for analysis. Your API key is sent with every request, and WordPress includes your website address in the request header.',
			'altimo'
		);
	}

	/**
	 * Names the moments in which data leaves the site, so nothing is sent at
	 * a time the user does not expect.
	 *
	 * @return string Translated text.
	 */
	public static function get_trigger_text() {
		return __(
			'Data is sent only when an image is optimized or regenerated (individually, in bulk, or automatically on upload if you enable auto-optimization), when you validate your API key, when the plugin checks your credit balance, and when AI suggestions for your Welcome settings are requested (once automatically right after you validate your API key or give this consent for an already saved key, and each time you click "Suggest with AI").',
			'altimo'
		);
	}

	/**
	 * Names the recipient of the transmitted data, its registered office, and
	 * links its privacy policy. States plainly -- not buried -- that the
	 * transfer leaves the EU into a third country (Brazil) under GDPR
	 * Art. 44 ff., states the transfer basis given in the privacy policy (the
	 * European Commission's adequacy decision for Brazil, with Standard
	 * Contractual Clauses (SCC) as an additional safeguard), names the AI
	 * gateway the recipient operates itself, and points to the privacy policy
	 * for the AI model vendor -- never naming any AI vendor here (D-10).
	 *
	 * @return string Translated recipient/third-country disclosure text.
	 */
	public static function get_recipient_text() {
		return sprintf(
			/* translators: 1: company name, 2: company address, 3: destination country, 4: privacy policy URL, 5: name of the AI gateway */
			__(
				'The recipient of the transmitted data is %1$s, %2$s. The AI analysis runs through %5$s, an AI gateway operated by %1$s, which forwards the image and the text data to the AI model vendor named in the privacy policy. This transfers the data to %3$s and leaves the EU into a third country under GDPR Art. 44 ff. The transfer is based on the European Commission\'s adequacy decision for Brazil, with Standard Contractual Clauses (SCC) as an additional safeguard. See the recipient\'s privacy policy for full details, including the AI model vendor used: %4$s',
				'altimo'
			),
			self::COMPANY_NAME,
			self::COMPANY_ADDRESS,
			self::COMPANY_COUNTRY,
			self::PRIVACY_POLICY_URL,
			self::AI_GATEWAY_NAME
		);
	}

	/**
	 * States the purpose of the data processing and the retention period:
	 * the service keeps a record of each request and anonymises its personal
	 * details after RETENTION_DAYS days; the downloaded image is not stored.
	 *
	 * @return string Translated purpose/retention disclosure text.
	 */
	public static function get_purpose_and_retention_text() {
		return sprintf(
			/* translators: %d: number of days after which the request records are anonymised */
			__(
				'The purpose of the processing is to generate SEO-optimized titles, alt texts, descriptions and filenames for your images, and to suggest a focus keyword, additional keywords and a website description for your site. The downloaded image is not stored. The service keeps a record of each image request (image URL, keywords, website description, language and the generated texts) and of your website (domain, server IP address, plugin version). After %d days, the personal details in these records are anonymised; only counters such as the number of requests, the language and the outcome remain.',
				'altimo'
			),
			self::RETENTION_DAYS
		);
	}

	/**
	 * States what happens to the site data sent for the AI suggestion of the
	 * Welcome settings (2.2.0).
	 *
	 * Kept as its own sentence on purpose: it must match what the service
	 * actually stores for /api/imageaiseo/site-profile. Until that is
	 * confirmed, it claims no retention period, only that the call is used for
	 * the suggestion and not stored beyond technical logs. Changing it
	 * materially requires a new Image_AI_SEO_Consent_Handler::TEXT_VERSION.
	 *
	 * @return string Translated text.
	 */
	public static function get_site_profile_retention_text() {
		return __(
			'The site data sent for AI suggestions is used only to generate the suggestions and is not stored beyond technical logs.',
			'altimo'
		);
	}

	/**
	 * One short sentence for the mandatory consent checkbox label (D-02).
	 *
	 * @return string Translated summary text.
	 */
	public static function get_summary_text() {
		return __(
			'I agree that data will be transmitted to Creative Skyline Ltda. (Brazil) for image optimization and AI suggestions for my site settings.',
			'altimo'
		);
	}

	/**
	 * The long-form disclosure for the <details> element (D-02), combining
	 * every D-04 category above into one HTML body. Rendered as HTML both in
	 * the settings view and, in Plan 06-04, in
	 * wp_add_privacy_policy_content() -- wrapped through wp_kses_post() since
	 * both consumers treat it as trusted HTML, not plain text.
	 *
	 * @return string Translated, wp_kses_post()-safe detail HTML.
	 */
	public static function get_detail_text() {
		$optimize_categories     = implode( ', ', self::get_optimize_data_categories() );
		$validation_categories   = implode( ', ', self::get_validation_data_categories() );
		$site_profile_categories = implode( ', ', self::get_site_profile_data_categories() );

		$html  = '<p>' . esc_html__( 'The following data is transmitted for every image optimization:', 'altimo' ) . ' ' . esc_html( $optimize_categories ) . '.</p>';
		$html .= '<p>' . esc_html__( 'Additionally, the following data is transmitted when validating your API key and when checking your credit balance:', 'altimo' ) . ' ' . esc_html( $validation_categories ) . '.</p>';
		$html .= '<p>' . esc_html__( 'When AI suggestions for your Welcome settings are requested, the following data is transmitted:', 'altimo' ) . ' ' . esc_html( $site_profile_categories ) . '.</p>';
		$html .= '<p>' . esc_html( self::get_image_handling_text() ) . '</p>';
		$html .= '<p>' . esc_html( self::get_trigger_text() ) . '</p>';
		$html .= '<p>' . esc_html( self::get_recipient_text() ) . '</p>';
		$html .= '<p>' . esc_html( self::get_purpose_and_retention_text() ) . ' ' . esc_html( self::get_site_profile_retention_text() ) . '</p>';

		return wp_kses_post( $html );
	}
}
