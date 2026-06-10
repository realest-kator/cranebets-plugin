# ⚙️ Crane Bets Core Plugin

This is the core functional engine of the Crane Bets platform. It runs as a strict Singleton and registers all custom post types, settings, database schemas, cron schedules, Paystack payout handlers, and prediction syncing utilities.

---

## 📁 Plugin Structure & Key Files

*   **`crane-bets-core.php`**: Plugin bootstrapper. Configures singleton instance, CPT registration (`crane_prediction`, `crane_locker_post`), sets admin pages, triggers/registers WP-Cron intervals, WooCommerce hook interceptions, and Paystack payout gateways.
*   **`includes/Prediction_API_Service.php`**: Controls data sync from **API-Football** and **The Odds API**. Handles manual sync notice generation, priority leagues, and the lookahead search loop.
*   **`includes/Free_Prediction_Scraper.php`**: Controls the **Forebet web scraper** and the custom African league scraper. Fallback when API-Football is disabled.
*   **`includes/VIP_Email_Service.php`**: Constructs and emails the daily VIP prediction blast at 8:00 AM WAT to active subscribers.
*   **`includes/User_Accuracy_Service.php`**: Calculates win ratios and assigns user badges (Novice, Senior, Enthusiast, Expert, Master).
*   **`includes/Affiliate_Payout_Service.php`**: Manages referrals, cookie storage, commission calculations, and Paystack bank transfers.

---

## ⚙️ Cron Jobs & Background Engine

The plugin registers a custom 6-hour interval (`crane_6hours` = 21600 seconds) to schedule background operations.

*   `crane_sync_predictions_cron_v2`: Triggered every **6 hours** to fetch fixtures.
*   `crane_sync_odds_cron`: Runs **2x daily** to pull betting odds.
*   `crane_cleanup_predictions_cron`: Runs **daily** to delete stale predictions.
*   `crane_vip_daily_email_cron`: Runs **daily at 8:00 AM WAT** to mail VIP picks.
*   `crane_fetch_news_cron`: Runs **hourly** to fetch RSS sports news.

---

## 🧠 Smart Syncing Rules (V1.1.2 Updates)

### 1. 14-Day Lookahead Search
To prevent the homepage from showing no matches (e.g., during league breaks or mid-week lulls), both Forebet and API-Football search for fixtures day-by-day up to **14 days** into the future. The query halts and imports on the **first day** that matches are found.

### 2. Today-Lock Guard
If matches exist for today (in active, paused, or finished states), the sync engine locks its focus to today. It will not fetch future dates until the 24-hour cleanup runs, ensuring today's active games are not hidden by future matches.

### 3. Immediate 24-Hour Cleanup
Stale predictions are cleaned up relative to their specific match time (instead of midnight). When current time is $\ge$ **24 hours past the match date/time**, the post is sent to the trash. Live matches are parsed dynamically using Regex.

### 4. Cache & WP Rocket Purging
After every sync execution:
- Deletes transient options (`crane_front_matches_html`, `crane_front_locker_preview`, `crane_front_matches_pool`).
- Purges page caching plugin domains: **WP Rocket**, **LiteSpeed Cache**, **W3 Total Cache**, and **WP Fastest Cache**.
- Transient writes are skipped on empty pools so visitor pages don't cache loading states.

---

## 🖥️ Manual Sync Reporting

Under **Settings > Crane Bets Settings**:
*   The **API-Football "Sync Now"** button provides detailed feedback:
    *   🟢 **Green Notice**: Displays count of synced/updated matches.
    *   🔴 **Red Notice**: Errors out if no API key is set.
    *   🟡 **Yellow Notice**: Warm warning if source is configured to Forebet Only.
    *   🔵 **Blue Notice**: Indicates matches are already up-to-date or no fixtures were found in 14 days.