=== Crane Bets Core ===
Contributors: ashiekaa
Requires PHP: 7.4
Requires at least: 6.0
Tested up to: 6.4
Version: 1.1
License: GPLv2 or later

== Description ==
Backbone functionality for the Crane Bets platform. Handles VIP subscriptions, prediction syncing, and user metrics.

== Installation ==
1. Upload the `crane-bets-core.zip` through the WordPress admin.
2. Activate the plugin.
3. Configure settings in the Crane Admin panel.

== Changelog ==
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
