# Done Purple Mail Queue

Send WordPress emails in the background. Form submissions respond instantly while emails still go out within seconds.

## Why

Sending email during a page request is slow — connecting to a remote SMTP server can add several seconds to every form submission. This plugin short-circuits `wp_mail()` via the `pre_wp_mail` filter, stores the email in a custom table, and delivers it moments later through [Action Scheduler](https://actionscheduler.org/) (the job queue WooCommerce uses), going back through the normal `wp_mail()` pipeline so your SMTP plugin still applies.

## How it works

1. `pre_wp_mail` intercepts the email and returns `true` — the page responds instantly.
2. The payload is stored in `{prefix}dpmq_emails` (not in Action Scheduler args, which cap at ~8 KB).
3. Attachments are **copied** to a protected uploads subdirectory — form plugins delete their temp files right after `wp_mail()` returns, so this is required for attachments to survive.
4. An async Action Scheduler action sends the email in the background (a bypass flag prevents re-interception). On `shutdown` we dispatch AS's async loopback runner ourselves — AS only self-dispatches from admin screens (`is_admin()` gate), so without this, emails queued from front-end/REST form submissions would wait for WP-Cron (minutes on a cached site).
5. The background send **claims** the row atomically (`UPDATE ... WHERE status IN ('queued','retrying')`), so duplicate actions can never double-send.
6. Failures retry up to 3 times with backoff, then are marked failed and visible under **Tools → Mail Queue** with a "Send again" action.
7. A watchdog runs every 5 minutes: rows stuck in `sending` (crashed runner) or `queued`/`retrying` with a lost action are re-queued, or failed out once attempts are exhausted.

## Safety behavior

- **Fails open**: if anything goes wrong at queue time (Action Scheduler missing, attachment copy fails, DB insert fails), the email is sent synchronously as before.
- **Password resets are synchronous by default** so users can always recover access, even with a broken cron. Override with the `dpmq_should_queue` filter.
- Health warnings on the admin page for a stuck queue, slow delivery (with server-cron setup instructions), or a foreign pluggable `wp_mail()` override (which would make interception impossible).
- A **Send test email** button on the admin page verifies interception + background delivery + speed in one click — use it when rolling out to a new site.
- **Deactivation** sends any unsent emails synchronously and unschedules all `dpmq` group actions, so nothing is lost or orphaned while the plugin is off.
- Sent emails purge after 7 days (setting), failed after 30. Uninstall removes the table, options, and files.

## Development

Action Scheduler 3.9.3 is bundled in `lib/` (requires WP 6.5+). Local testing works well with [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) or LocalWP plus a mail catcher (MailHog / Mailpit).

Rollout plan: local → DonePurple.com → C Lazy U staging.

## License

GPL-2.0-or-later.
