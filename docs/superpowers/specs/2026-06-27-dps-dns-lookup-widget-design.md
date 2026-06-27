# DPS DNS Lookup Widget Design

## Goal

Convert the existing WPCode `html.html` DNS bulk lookup snippet into a standard WordPress plugin that is suitable for a wordpress.org-style repository, keeps the DPS.MEDIA branded tool experience, and improves performance, security, and maintainability.

## Architecture

The plugin exposes a shortcode, `[dps_dns_lookup]`, that renders a single scoped widget root and enqueues one CSS file and one JavaScript file. The frontend no longer embeds a large inline script in post content.

DNS requests go through a WordPress REST endpoint at `/wp-json/dps-dns-lookup/v1/lookup`. The endpoint verifies a nonce, validates the domain and record type, applies a lightweight IP-hash rate limit, checks a transient cache, and calls Google DNS-over-HTTPS through the WordPress HTTP API when cache is missing.

## Components

* `dps-dns-lookup-widget.php`: plugin header, constants, bootstrap.
* `includes/class-dps-dns-lookup-plugin.php`: shortcode rendering and asset registration.
* `includes/class-dps-dns-lookup-rest.php`: REST route, validation, cache, rate limit, DNS provider call.
* `assets/js/dps-dns-lookup.js`: UI state, batching, progress, copy TSV.
* `assets/css/dps-dns-lookup.css`: isolated, responsive UI.
* `readme.txt`: wordpress.org-style metadata and usage.

## UX

The interface is a compact operational tool, not a landing page. It prioritizes quick paste-run-copy workflows:

* Paste domains or URLs, one per line.
* Select one record type or ALL.
* Set delay, run, stop, clear, and copy TSV.
* See progress and per-record rows.

The visual direction uses DPS.MEDIA navy and green, restrained borders, tabular result rows, and scoped classes prefixed with `dps-dns-`.

## Error Handling

The frontend shows user-readable messages for empty input, domain limits, invalid DNS responses, rate limits, network failures, and stopped runs. The REST endpoint returns `WP_Error` objects with appropriate HTTP statuses for invalid nonce, invalid domain, invalid type, provider failures, and rate limits.

## Testing

This repo uses static verification because it is a standalone plugin folder without a full WordPress test harness. Verification checks include PHP syntax linting, expected WordPress APIs, shortcode registration, REST route registration, nonce usage, escaping/sanitization calls, no CDN dependency, and asset presence.
