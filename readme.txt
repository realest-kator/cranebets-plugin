=== Crane Bets Core ===
Contributors: ashiekaa
Requires PHP: 7.4
Requires at least: 6.0
Tested up to: 6.4
Version: 1.1.4
License: GPLv2 or later

== Description ==
Backbone functionality for the Crane Bets platform. Handles VIP subscriptions, prediction syncing, and user metrics.

== Installation ==
1. Upload the `crane-bets-core.zip` through the WordPress admin.
2. Activate the plugin.
3. Configure settings in the Crane Admin panel.

== Changelog ==
= 1.1.4 =
* Implemented strict, deterministic sync flow (Today -> Tomorrow -> Scan up to 14 days) terminating on the first date with active matches.
* Set prediction posts to 'draft' status by default. They are only published once a valid, non-pending prediction tip is fetched/scraped.
* Prevented Forebet matches with empty predictions from publishing or defaulting to 'HOME WIN' (they are skipped entirely).
* Implemented cross-source merging so Forebet scraper will update and publish existing draft posts once predictions are scraped.

= 1.1.3 =
* Separated API-Football and Forebet scraper into two independent cron events.
* API-Football now fires at exact WAT slots: 00:00, 06:00, 12:00, 18:00 (Nigerian time).
* Forebet scraper fires 30 minutes later: 00:30, 06:30, 12:30, 18:30 WAT.
* Both still run every 6 hours but are now anchored to fixed clock times.
* Admin settings page now shows the next scheduled run time for both engines separately.

= 1.1.2 =
* Reduced prediction sync CRON interval from 2 hours to 6 hours to lower server load.
* Extended prediction search window from 7 days to 14 days to prevent empty matches during quiet football periods.
* Added a Today-Lock guard so future predictions don't crowd out today's games.
* Switched cleanup routine to immediate 24-hour post-match deletion based on actual match time.
* Added automatic cache purging supporting WP Rocket, LiteSpeed Cache, W3TC, and WP Fastest Cache.
* Added color-coded native admin notices to the API-Football manual "Sync Now" button detailing exact sync results or validation errors.

= 1.1.1 =
* Fixed match date parsing & WAT (Africa/Lagos) timezone conversion.
* Skip non-active match statuses (e.g. FT, PST, ABD) and pending prediction cards.
* Clean up past predictions relative to Lagos midnight daily.

= 1.1 =
* Expanded league and match coverage (added 12+ new leagues and FIFA World Cup 2026).
* Added automated tournament detection logic.
* Upgraded scraper transport to dual-mode (curl.exe shell bypass for Cloudflare HTTP 403 blocks).
* Fixed correct score parser, league label classification, and timezone offset mapping.
* Optimized transient caching limits during active tournaments.

= 1.0.0 =
* Initial production release.
* Migrated predictions to custom DB tables for performance.
* Hardened VIP timer security.
