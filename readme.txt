=== Altimo – AI Image SEO ===
Contributors: creativeskyline
Tags: alt text, image seo, rename images, ai, accessibility
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI writes title, alt text, description and SEO file name for your images in 28 languages. Renames files safely with 301 redirects and one-click undo.

== Description ==

Altimo writes the SEO attributes of your Media Library images with AI: the title, the alt text, the description and a descriptive file name. It works from your focus keyword, additional keywords and a short description of your site, and it writes in the language of your audience.

= Four attributes, not only alt text =

Each optimization writes title, alt text, description and file name in one pass. In the preview you choose which of them are applied and can edit every suggestion before it is saved.

= 28 output languages =

Arabic, Chinese, Czech, Danish, Dutch, English, Finnish, French, German, Greek, Hebrew, Hindi, Hungarian, Indonesian, Italian, Japanese, Korean, Norwegian, Polish, Portuguese, Romanian, Russian, Spanish, Swedish, Thai, Turkish, Ukrainian and Vietnamese. The output language is independent of the language of your WordPress admin.

= Safe file renames with 301 redirects =

A descriptive file name instead of `IMG_4711.jpg` helps image search, but renaming a file can break every link to it. Altimo renames the file and all its generated sizes inside your uploads folder, updates the references in your post content (including common page builders), and answers requests for the old URL with a 301 redirect, so links, embeds and search results keep working.

= Undo anything =

Every change is recorded in the history. Revert a single change or a whole selection at once, restore it again, or regenerate an image. A revert also undoes the file rename.

= Uses the focus keyword of your SEO plugin =

If an image is attached to a post, Altimo uses that post's focus keyword from Yoast SEO, Rank Math or All in One SEO, so the image matches the page it appears on. Without one, it uses the focus keyword from the plugin settings. Altimo is not affiliated with these plugins.

= Preview, bulk runs and the Media Library =

* Preview the suggested title, alt text, description and file name before anything is applied.
* Optimize all images in bulk. Before a run starts, Altimo checks that your credits cover it.
* A status column and a filter in the Media Library show which images are optimized, and the "Optimize with AI" bulk action works right from the list.
* Optional: optimize new uploads automatically (off by default).

= Nothing leaves your site before you agree =

No data is sent to the service until you have granted consent on the License tab. This is a hard gate, not a setting you can miss: without consent, no request ever leaves your site. The External Services section below lists exactly what is sent, when and to whom.

= Multisite =

Settings, credits and consent are scoped per site, while a network administrator can also manage the API key for the whole network.

= The service behind it =

Altimo needs an account and an API key for the Altimo service at imageaiseo.com. New accounts get free starter credits, and you pay only for the credits you use; the plugin always shows the balance your account really has.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/altimo`, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. The plugin opens its Welcome tab. Open the License tab and enter your API key.
4. Grant consent to the data transmission described in the External Services section below. Optimization is blocked until this step is completed.
5. Back on the Welcome tab, the AI fills the empty focus keyword, additional keywords and site description fields once. Review them (or use "Suggest with AI" later), then start optimizing images from the Media Library.

== Frequently Asked Questions ==

= I used Image AI SEO before. How do I switch? =

Image AI SEO is now Altimo. Install and activate Altimo: it picks up your API key, settings, consent and history, because both use the same data. Then deactivate the old Image AI SEO plugin. Delete it only once it is on version 2.3.3 or later (download at https://imageaiseo.com/download); older versions delete the shared settings when they are deleted. While both are active, only Altimo runs, and a notice on the Plugins screen tells you what to do.

= I use Image AI SEO Pro. What should I do? =

Image AI SEO Pro is discontinued; Altimo has the same features. Install and activate Altimo, then deactivate Image AI SEO Pro. Do not delete Pro on the Plugins screen: its uninstall routine removes the shared API key, settings and history. Remove its folder `wp-content/plugins/image-ai-seo-pro` by FTP or in your hosting file manager instead; WordPress then runs no uninstall routine.

= How many free credits do I get? =

New accounts on imageaiseo.com receive free starter credits. The amount is set by the service, not by the plugin: after you validate your API key, the plugin shows the balance your account really has.

= What happens when I run out of credits? =

Credits are checked server-side before every optimization. Once your remaining credits reach zero, the plugin blocks further optimizations and shows a notice pointing you to purchase more credits. No partial or unmetered requests are made.

= Do I need to grant consent, and what does it cover? =

Yes. Before any image data, API-key validation data or site data for the AI suggestions is sent, you must grant consent in the plugin's settings. See the External Services section below for exactly which data is sent, to whom, and why. You can revoke consent at any time, which immediately blocks further outbound requests.

= What happens to my data when I uninstall the plugin? =

Uninstalling removes the plugin's own settings, credits, consent record and the two custom database tables it created (across every site on a multisite network). It does not delete the optimization-status metadata already stored on your attachments; see the Uninstall section below for details.

== Screenshots ==

1. License tab: enter your API key and agree to the data-processing notice before anything is sent.
2. Settings: focus keyword, additional keywords, website description and the output language.
3. Preview dialog: review the suggested title, alt text, description and filename before they are applied.
4. Bulk optimization with progress display and a credit check before the run starts.
5. History: revert or restore any change, including the file rename.
6. Media Library column showing the optimization status of every image.

== Changelog ==

= 3.0.1 =
* The credit hint for a bulk run uses singular and plural correctly ("1 image will use 1 credit") and is translatable in every language.
* The field in the attachment details is labelled "Altimo" instead of "AI SEO".
* The notice about switching from Image AI SEO links the download page.

= 3.0.0 =
* Image AI SEO is now called Altimo – AI Image SEO. Your API key, settings, consent and history carry over.
* File renames stay inside the uploads folder; a rename that would leave it is refused.
* Portable database table schema for the history and redirect tables.
* Notices appear only on the screens where they matter, and the admin scripts and styles load only where they are needed.
* Translations come from translate.wordpress.org.

= 2.3.3 =
* Prepares the switch to the renamed plugin "Altimo – AI Image SEO", the name under which wordpress.org lists this plugin. Deleting this copy keeps your API key, settings, consent and history when Altimo is installed.
* While Altimo is active, this copy pauses itself instead of running twice.
* The notice of a paused copy appears only on the Plugins screen.

= 2.3.2 =
* The plugin header now names the author's WordPress.org profile as Author URI, separate from the plugin's own website. No functional changes.

= 2.3.1 =
* The data-processing notice no longer mentions the update check of the discontinued Pro version. Existing sites are asked to confirm the updated notice once; optimization pauses until then.
* Fix: the bulk optimization tab now shows "Optimizing image X of Y" below the progress bar; the bar used to hide it.
* Fix: the credit hint on the bulk optimization tab ("Optimizing N images will use N credits") now updates during and after a run, together with the credit badge.
* Tested with WordPress 7.1.

= 2.3.0 =
* Image AI SEO is now the only edition. Image AI SEO Pro is discontinued; this plugin has the same features.
* If Image AI SEO and Image AI SEO Pro are both active, only one of them runs and a notice says which one to keep, instead of a fatal error.
* Deleting one of the two plugins while the other one is still installed keeps the settings, API key, consent and history.

= 2.2.1 =
* Fix: the credit badge now updates together with the credit count after connecting a key.

= 2.2.0 =
* After activation the plugin opens the Welcome tab instead of the License tab. It does not redirect after a bulk or network-wide activation, through WP-CLI, or for users who cannot manage the plugin, and only once.
* On activation, an empty website description is prefilled from your site's tagline, and an empty focus keyword from the focus keyword your SEO plugin stores for a static front page. Fields that already contain text are never changed, and nothing is sent.
* New "Suggest with AI" button on the Welcome tab: the AI suggests a focus keyword, additional keywords and a website description from your site name, tagline, address, language and recent titles. Right after you validate your API key it fills the empty fields once on its own.
* The data-processing notice now lists the data sent for these suggestions. Existing sites are asked to confirm the updated notice once; optimization pauses until then. Sites that already have an API key get the one-time AI suggestion for their empty Welcome fields right after confirming.

= 2.1.1 =
* Corrected the data-processing notice: it now states how long the service keeps request data (anonymised after 90 days), names the AI gateway FlowModels, and lists every moment data is sent. Existing sites are asked to confirm the updated notice once; optimization pauses until then.
* Undoing a change in the history now resets the image to "Not optimized" when it is back in its original state, so the counters and the next bulk run include it again. Images left marked as optimized by an earlier undo are repaired once during the update.
* Redesigned preview dialog: the image next to full-width fields, a larger description field and aligned checkboxes.
* After a bulk run the image counts, the credit hint and the start button are refreshed; with nothing left the button shows "All images optimized".
* The plugin header shows the Image AI SEO mark as a crisp vector graphic.
* A bulk run stops with a clear message when consent is missing instead of skipping every image.
* The License tab no longer promises a fixed number of free credits; it shows your real balance.
* Admin notices appear only on relevant screens and can be dismissed.
* Security hardening: per-image permission checks, prefixed AJAX actions, stricter input handling, no direct file access.
* The Free build no longer contains any update-client code, and release archives can no longer contain hidden files.

= 2.1.0 =
* The plugin now talks to its own service at imageaiseo.com.
* Pro installations receive updates from imageaiseo.com.
* The purchase, website and privacy links point to imageaiseo.com.

= 2.0.0 =
* Full compatibility with WordPress 7.
* Safe image file renaming with automatic 301 redirects, so links and embeds keep working after a rename.
* Preview of suggested title, alt text, description and filename changes before anything is applied.
* Multisite support: per-site settings, credits and consent, with network-wide license management.
* A GDPR-compliant consent gate that blocks all outbound requests until the site owner explicitly agrees to the data transmission described below.

== External Services ==

This plugin relies on the Altimo service at imageaiseo.com, operated by Creative Skyline Ltda., Avenida Washington Soares 55, 60811-341 Fortaleza, Brazil. The service generates the titles, alt texts, descriptions and filenames; without it, the plugin cannot optimize images. No data is sent before you grant consent in the plugin's settings.

What is sent, and when:

* Image optimization (a single image, a bulk run, automatic optimization of new uploads if you enable it, and "Regenerate" in the history): the image URL, your focus keyword, additional keywords, website description, the output language and your API key. The service downloads the image from this URL, reduces its size and passes it to the AI model for analysis.
* API key validation and the credit balance check: your API key, the website domain, the server IP address and the plugin version.
* AI suggestions for the Welcome settings (once automatically right after you validate your API key or give consent for an already saved key, and each time you click "Suggest with AI"): your API key, the site name, the site tagline, the website address, the WordPress language (locale), the output language, whether WooCommerce is active, and the titles of up to 20 recently published posts, pages and products.
* With every request, WordPress also sends your website address in the request header.

The AI analysis runs through FlowModels, an AI gateway operated by Creative Skyline Ltda., which forwards the image and the text data to the AI model vendor named in the privacy policy. This transfers the data to Brazil and leaves the EU into a third country under GDPR Art. 44 ff. The transfer is based on the European Commission's adequacy decision for Brazil, with Standard Contractual Clauses (SCC) as an additional safeguard.

The purpose of the processing is to generate SEO-optimized titles, alt texts, descriptions and filenames for your images, and to suggest a focus keyword, additional keywords and a website description for your site. The downloaded image is not stored. The service keeps a record of each image request (image URL, keywords, website description, language and the generated texts) and of your website (domain, server IP address, plugin version). After 90 days, the personal details in these records are anonymised; only counters such as the number of requests, the language and the outcome remain. The site data sent for AI suggestions is used only to generate the suggestions and is not stored beyond technical logs.

* Terms of service: https://imageaiseo.com/terms
* Privacy policy: https://imageaiseo.com/privacy

== Upgrade Notice ==

= 3.0.1 =
Small fixes: correct singular and plural in the bulk credit hint, the attachment field label and a linked download address in the switch notice.

= 3.0.0 =
Image AI SEO is now Altimo – AI Image SEO. Your API key and settings carry over. If the old Image AI SEO plugin is still installed, deactivate it, and delete it only once it is on version 2.3.3 or later.

= 2.3.3 =
Prepares the switch to the renamed plugin "Altimo – AI Image SEO". Deleting this copy keeps your data when Altimo is installed.

= 2.3.2 =
Maintenance release without functional changes.

= 2.3.1 =
The data-processing notice was shortened (the update check of the discontinued Pro version is gone). Optimization pauses until you confirm the updated notice once on the License tab; your API key and settings are kept.

= 2.3.0 =
Image AI SEO Pro is discontinued. If you use it, install this plugin and read the FAQ entry for Pro users before you delete Image AI SEO Pro: versions older than 2.3.0 remove your settings, API key and history when deleted.

= 2.2.1 =
Fixes the credit badge in the plugin header, which could still say "No credits remaining!" next to the new balance after you connected your API key. No action needed.

= 2.2.0 =
New AI suggestions for the Welcome settings. The data-processing notice now lists the site data they send, so optimization pauses until you confirm the updated notice once on the License tab; your API key and settings are kept.

= 2.1.1 =
The data-processing notice was corrected (retention period, AI gateway, every moment data is sent). Optimization pauses until you confirm the updated notice once on the License tab; your API key and settings are kept.

== Uninstall ==

Uninstalling this plugin removes its settings (API key, credits, language, keywords, site description, consent record, the record of the one-time AI suggestion and database version) and drops its two custom database tables. On a multisite network, this runs for every site, plus the network-scoped license option.

While the old Image AI SEO plugin or the discontinued Image AI SEO Pro (both use the same data) is still installed, deleting Altimo removes nothing. Image AI SEO 2.3.3 and later do the same the other way round, older versions and Image AI SEO Pro do not (see the FAQ). The data is removed when the last copy is deleted.

The plugin does not delete the `_image_ai_seo_optimized` and `_image_ai_seo_needs_attention` postmeta values already stored on your attachments. This is a deliberate choice, not an oversight: these values are part of your existing media library content, and WordPress' own convention is to leave a site's content untouched when a plugin is removed. If you reinstall the plugin later, this preserves the record of which images were already optimized.
