=== SEOWriting ===
Contributors: SEOWriting
Tags: seo writing, AI tool, AI writing, generation text
Tested up to: 6.6
Requires at least: 4.9
Requires PHP: 5.6.20
Stable tag: 1.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI writing assistant for creating SEO-optimized content with auto-publishing & scheduling posts on WordPress websites.

== Description ==

[SEOWriting](https://seowriting.ai/?utm_source=wp_plugin "SEOWriting")'s AI-powered writing assistant is designed to create SEO-optimized content with images and relevant videos from YouTube in 1-Click and Bulk modes. SEOWriting provides auto-posting to WordPress websites, allowing users to publish & schedule their content directly from the platform.

[youtube https://www.youtube.com/watch?v=Q10tXx2QE4E]

SEOWriting’s powerful plugin allows you to seamlessly publish titles, texts, images, meta titles, and meta descriptions on your WordPress website. The installation process is quick and easy, and you can find a step-by-step guide [here](https://docs.seowriting.ai/article/wordpress-integration?utm_source=wp_plugin "Plugin installation guide").

The plugin uses the REST-API provided by [https://seowriting.ai/](https://seowriting.ai/?utm_source=wp_plugin). The Service is provided under the terms of [Terms of Service](https://seowriting.ai/terms-of-service?utm_source=wp_plugin) and [Privacy Policy](https://seowriting.ai/privacy-policy?utm_source=wp_plugin).

== Changelog ==

= 1.8.1 (2024/07/19) =

Feature:
* Interface improvements.

= 1.8.0 (2024/07/19) =

Feature:
* Renaming the plugin to protect against external scanning.

= 1.7.1 (2024/07/12) =

Feature:
* Paginated export posts to create knowledge base.

= 1.7.0 (2024/07/04) =

Feature:
* Export posts to create knowledge base.
* Authorization on the [https://seowriting.ai/](https://seowriting.ai/?utm_source=wp_plugin_changelog) website is performed in a new tab.

= 1.6.2 (2024/06/13) =

Feature: Multiple keyword support in some plugins.

= 1.6.1 (2024/05/23) =

Feature: Internal changes.

= 1.6.0 (2024/05/23) =

Feature: Ability to disable schema.org markup.

= 1.5.6 (2024/04/11) =

Feature: Internal changes to the plugin to determine the user who activated the plugin.

= 1.5.5 (2024/04/10) =

Feature: Internal changes to the plugin to determine the user who activated the plugin.

= 1.5.4 (2024/04/08) =

Fix: The correct way to pass the author ID from the backend to the plugin

= 1.5.3 (2024/04/08) =

Feature: Internal changes to get a list of authors who are allowed to publish

= 1.5.2 (2024/03/21) =

Fix: The default plugin uses production endpoint.

= 1.5.1 (2024/03/11) =
Fix: Plugin renamed back according to the requirements of wordpress.org catalog moderators.

= 1.5.0 (2024/03/11) =
Fix: Plugin renamed according to the requirements of wordpress.org catalog moderators.

= 1.4.17 (2024/03/05) =
Fix: Escaping the output of plugin according to the requirements of wordpress.org catalog moderators.

= 1.4.16 (2024/02/20) =
Feature: Changed the method of storing and retrieving error logs.
Fix: Escaping the output of plugin according to the requirements of wordpress.org catalog moderators.

= 1.4.15 (2024/02/12) =
Fix: Escaping the output of plugin settings according to the requirements of wordpress.org catalog moderators.

= 1.4.14 (2024/02/12) =
Fix: Removed the limit on the number of categories the plugin receives.

= 1.4.13 (2024/02/07) =
Feature: Better text encoding for JSON_LD markup.

= 1.4.12 (2024/02/07) =
Fix: Correct processing of HTML coming to the WP from the service to display schema.org markup.

= 1.4.11 (2024/02/07) =
Feature: By default, splitting a post into blocks for Elementor is disabled.
Fix: Splitting a post into blocks in Elementor with multibyte language support.

= 1.4.10 (2024/02/07) =
Fix: Path to the API for the production version of the plugin.

= 1.4.9 (2024/02/06) =
Feature: Debug mode to interact with the client's WP when a service request is received from the client.

= 1.4.8 (2024/02/06) =
Feature: Ability to disable splitting a post into Elementor blocks in the settings.

= 1.4.7 (2024/02/06) =
Fix: Forced setting of utf-8 encoding for correct processing of multibyte languages in Elementor parser.
Fix: Escaping the output of plugin settings according to the requirements of wordpress.org catalog moderators.

= 1.4.6 (2024/02/03) =
* Feature: minimum supported version of PHP is set to 5.6.20
* Feature: internal changes
* Fix: output of Schema.org markup as node attributes or as JSON-LD

= 1.4.5 (2024/02/02) =
* Feature: Hiding libxml internal errors when parsing HTML for Elementor

= 1.4.4 (2024/01/30) =
* Fix: video publishing in Elementor

= 1.4.3 (2024/01/30) =
* Feature: publishing posts with a date in the past

= 1.4.2 (2024/01/30) =
* Fix: more accurate checking of content type in the API

= 1.4.1 (2024/01/30) =
* Fix: compatibility with PHP 5.6. and WP 4.9

= 1.4.0 (2024/01/26) =
* Feature: Youtube video in Elementor markup
* Fix: JSON-LD output

= 1.3.1 (2024/01/24) =
* Fix: common way of defining the context of a file call method

= 1.3.0 (2024/01/24) =
* Feature: possibility to create `post_excerpt` when sending data from seowriting.ai service

= 1.2.3 (2024/01/23) =
* Feature: fillable `focus keyphrase`, `og:title` and `og:description` for the `All in one SEO` plugin
* Feature: fillable `og:title`, `og:description`, `twitter:title`, `twitter:description` for the `The SEO Framework` plugin
* Feature: fillable `og:title`, `og:description`, `twitter:title`, `twitter:description` for the `SEOPress` plugin

= 1.2.2 (2024/01/23) =
* Feature: internal changes to create two types of releases: a) for all clients b) for internal testing

= 1.2.1 (2024/01/23) =
* Feature: added utm-tags to the readme.txt file

= 1.2.0 (2024/01/23) =
* Feature: meta tags support for `Squirrly SEO (Newton)` plugin

= 1.1.9 (2024/01/22) =
* Update: readme.txt
* Fix: comments on the code sent by the plugin moderation team

= 1.1.8 =
* Publish content from SEOWriting to WordPress.
