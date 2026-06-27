# DPS DNS Lookup Widget

Standard WordPress plugin version of the original WPCode DNS bulk lookup snippet in `html.html`, upgraded with multi-column DNS and server health checks.

## Usage

Install the plugin folder in WordPress and add this shortcode to a post, page, or template:

```text
[dps_dns_lookup]
```

Optional attributes:

```text
[dps_dns_lookup limit="100" delay="120" title="Tra Cứu DNS Hàng Loạt"]
```

## What Changed From The Snippet

- Large inline WPCode HTML/JS was converted into a WordPress plugin.
- CSS and JavaScript are enqueued as static assets.
- DNS lookups go through a WordPress REST endpoint.
- DNS, HTTP, and SSL checks go through a WordPress REST endpoint.
- Results are cached with transients.
- Admin settings control HTTP/SSL availability, rate limits, allow/block lists, and optional logs.
- Shortcode output uses escaping, sanitization, and scoped classes.

## Verification

Run from the plugin root:

```bash
php -l dps-dns-lookup-widget.php
php -l includes/class-dps-dns-lookup-settings.php
php -l includes/class-dps-dns-lookup-admin.php
php -l includes/class-dps-dns-lookup-plugin.php
php -l includes/class-dps-dns-lookup-rest.php
php -l uninstall.php
node --check assets/js/dps-dns-lookup.js
php tests/static-checks.php
```

## WordPress.org Notes

The plugin includes a wordpress.org-style `readme.txt`, GPLv2-or-later licensing metadata, a unique prefix (`dps-dns-`), no CDN dependencies, no secrets, and no build step.
