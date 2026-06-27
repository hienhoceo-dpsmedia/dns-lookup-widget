=== DPS DNS Lookup Widget ===
Contributors: dpsmedia
Tags: dns, lookup, ssl, http status, shortcode, tools, doh
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast bulk DNS and server health lookup widget for WordPress, powered by a cached REST endpoint and shortcode.

== Description ==

DPS DNS Lookup Widget converts a standalone WPCode HTML snippet into a standard WordPress plugin. Add the shortcode `[dps_dns_lookup]` to any post, page, or template area to render a scoped DNS lookup tool.

Features:

* Bulk DNS lookup from one domain per line.
* URL-to-hostname extraction.
* Multi-select pivot table: domains as rows and checks as columns.
* Record types: A, AAAA, CNAME, MX, NS, TXT, CAA, and SOA.
* Optional HTTP status and SSL certificate checks.
* Cached WordPress REST proxy for better performance.
* Admin settings for server checks, rate limits, IP allow/block lists, and optional logs.
* Copy pivot results as Excel-friendly TSV.
* Responsive, scoped UI that avoids theme CSS collisions.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate "DPS DNS Lookup Widget" in WordPress.
3. Add `[dps_dns_lookup]` to a post or page.

== Shortcode ==

Basic:

`[dps_dns_lookup]`

Optional attributes:

`[dps_dns_lookup limit="100" delay="120" title="Tra Cứu DNS & Server Hàng Loạt"]`

Legacy shortcode alias:

`[dps_bulk_dns]`

== Frequently Asked Questions ==

= Does this call Google DNS directly from the browser? =

No. The browser calls a WordPress REST endpoint. WordPress validates the request, checks cache, applies a lightweight rate limit, then uses the WordPress HTTP API to call Google DNS-over-HTTPS.

= Is this compatible with cache plugins? =

Yes. The widget JavaScript calls a REST endpoint for live lookups, while static assets can be cached normally.

== Changelog ==

= 1.1.6 =

* Set up official automated build & release pipeline via GitHub Actions.
* Fixed zip file extraction compatibilities for standard WordPress plugin uploads.

= 1.1.5 =

* Implemented a native URL and locale-based translation dictionary to automatically translate the widget to English/Chinese on /en/ and /zh/ paths.

= 1.1.4 =

* Fixed TranslatePress compatibility by rendering translation strings inside a hidden container rather than custom data attributes.

= 1.1.3 =

* Improved translation compatibility with TranslatePress and Multi AI.
* Moved dynamic JavaScript strings to PHP backend data attributes.

= 1.1.2 =

* Simplified table output for cleaner copy/export values.
* DNS columns now show the first answer only.
* HTTP now shows only the numeric status code.
* SSL now shows only the number of days remaining.
* Added a SERVER column for Cloudflare, nginx, LiteSpeed, Apache, or the detected server header.

= 1.1.1 =

* Fixed stale REST nonce errors on cached pages by refreshing the nonce and retrying once.
* Restored Vietnamese UI text with proper diacritics.

= 1.1.0 =

* Added multi-select pivot table mode.
* Added HTTP status and SSL certificate checks.
* Added admin settings, allow/block lists, rate limit controls, and optional logs.
* Added legacy `[dps_bulk_dns]` shortcode alias.

= 1.0.0 =

* Initial plugin release.
