=== Done Purple Mail Queue ===
Contributors: donepurple
Tags: email, queue, background, performance, wp_mail
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress emails in the background. Form submissions respond instantly while emails still go out within seconds.

== Description ==

Sending email during a page request is slow: connecting to an SMTP server can add several seconds to every form submission, registration, or checkout. Done Purple Mail Queue intercepts outgoing email, responds to the visitor instantly, and delivers the email in the background within seconds using the battle-tested Action Scheduler library (the same job queue WooCommerce uses).

**Features**

* Zero configuration — activate and every `wp_mail()` call is queued and sent in the background.
* Works with your existing SMTP plugin (WP Mail SMTP, FluentSMTP, etc.) — queued emails are delivered through the normal `wp_mail()` pipeline.
* Attachments are preserved: files are copied to a protected directory at queue time, so temp files deleted by form plugins still get attached.
* Automatic retries with backoff (3 attempts), then the email is marked failed.
* Email log under Tools → Mail Queue: see queued, sent, and failed emails; re-send or delete any of them.
* Password-reset emails are sent synchronously by default so users are never locked out if the background runner breaks.
* Instant background delivery even on cached, low-traffic sites: the async runner is dispatched right after each queued email, without waiting for WP-Cron.
* A watchdog recovers crashed or lost sends every 5 minutes; an atomic claim guarantees an email can never be delivered twice.
* "Send test email" button and a delivery-speed report on the admin page, so verifying a new site takes one click.
* Health warnings when the queue is stuck, delivery is slow, or another plugin has replaced `wp_mail()` entirely.
* Sent emails are purged after 7 days (configurable); failed emails after 30 days.
* Deactivating the plugin delivers any still-queued emails synchronously and cleans up its scheduled actions.

**Developer filters**

* `dpmq_should_queue` — decide per-email whether to queue (`true`) or send synchronously (`false`). Receives the `wp_mail()` arguments.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. That's it. Visit Tools → Mail Queue to see the log and settings.

== Frequently Asked Questions ==

= Does this replace my SMTP plugin? =

No. It queues the email, then delivers it through the normal WordPress mail pipeline, so your SMTP plugin's configuration still applies.

= What if WP-Cron is disabled or broken? =

Action Scheduler triggers an async loopback request so sends normally happen within seconds regardless. If emails do sit in the queue for more than 10 minutes, the Tools → Mail Queue page shows a warning. Password resets are sent synchronously by default, so login recovery keeps working either way.

= Where is email content stored? =

Queued and logged emails are stored in a custom database table and purged automatically (sent after 7 days by default, failed after 30). Attachments are copied to a protected uploads subdirectory and removed with the log entry.

== Changelog ==

= 0.2.0 =
* Fixed: emails queued from front-end and REST requests (i.e. every form submission) waited for WP-Cron — minutes on cached sites. The async runner is now dispatched immediately after queueing.
* Added: watchdog action (every 5 minutes) that re-queues emails whose send crashed or whose scheduled action was lost, and fails out emails with exhausted attempts.
* Added: atomic row claim — a duplicate scheduled action can no longer cause a double-send.
* Added: "Send test email" button on Tools → Mail Queue.
* Added: delivery-speed report and a slow-delivery warning with server-cron setup instructions.
* Improved: timestamps display in the site's timezone with a relative hint, plus a new Delivery column showing queue → send duration.
* Improved: deactivation now sends any unsent emails synchronously and removes the plugin's scheduled actions.

= 0.1.0 =
* Initial release.
