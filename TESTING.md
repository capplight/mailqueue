# QA checklist — Done Purple Mail Queue

Run top to bottom on each site before calling it good. Every step says what to do
and exactly what you should see. Total time: ~20 minutes.

**Setup:** a mail catcher (Mailpit/MailHog) locally, or a real inbox you control
on staging/production. Keep two tabs open: **Tools → Mail Queue** and
**Tools → Scheduled Actions**.

## 1. Activation

- [ ] Activate the plugin. No errors, no white screen.
- [ ] **Tools → Mail Queue** appears and renders: settings box, "Send a test
      email" box, empty (or existing) log table.
- [ ] **Tools → Scheduled Actions** → search `dpmq`: a recurring `dpmq_purge`
      (daily) and `dpmq_watchdog` (every 5 min) are pending.
- [ ] No red health notice. If you see *"another plugin has replaced the
      wp_mail() function"* — **stop**: interception is impossible on this site;
      note which file the notice names.

## 2. Test email button (happy path)

- [ ] Click **Send Test Email** (to your address). Notice appears: *"Test email
      queued…"*.
- [ ] The row shows in the log — status `Queued` or already `Sent`.
- [ ] Refresh after ~5 seconds: status `Sent`, **Delivery** column shows a few
      seconds at most, attempts = 1.
- [ ] The email actually arrived in the inbox / mail catcher.

## 3. Real form submission (front-end path — the important one)

This exercises the REST/front-end dispatch fix; the admin test button alone
does not.

- [ ] Submit the site's contact form **as a logged-out visitor** (incognito).
- [ ] The form responds fast (no multi-second SMTP wait in DevTools → Network).
- [ ] Within ~10 seconds the email is `Sent` in the log and arrives.
- [ ] Delivery column shows seconds, not minutes. If it shows minutes, loopback
      requests are likely blocked on this host — check Site Health → the
      "Delivery is slow" notice will appear after ~10 emails with cron setup
      instructions.

## 4. Attachments

- [ ] Submit a form that includes a file upload (or send a test wp_mail with an
      attachment).
- [ ] Email arrives **with the attachment intact**.
- [ ] `wp-content/uploads/dpmq-attachments/<id>/` exists while the row exists,
      and the directory is **not** web-readable
      (`https://site.com/wp-content/uploads/dpmq-attachments/` → 403/blank).

## 5. Failure and retry

- [ ] Break the SMTP settings temporarily (wrong password in Post SMTP / your
      SMTP plugin), send a test email.
- [ ] Row goes `Retrying` with the SMTP error shown; Scheduled Actions shows a
      `dpmq_send_email` retry ~1–2 min out.
- [ ] After 3 attempts total the row is `Failed` (red) with the error message.
- [ ] Fix SMTP, click **Send again** on the failed row → it re-queues and goes
      `Sent`.

## 6. Password reset stays synchronous

- [ ] Log out, use "Lost your password?".
- [ ] The reset email does **not** appear in the Mail Queue log (it is sent
      synchronously, on purpose) and it arrives.

## 7. Kill switch and fail-open

- [ ] Untick **Background sending** in settings, save, submit the form: email
      is NOT intercepted (no new log row) but still arrives (synchronous).
      Re-tick afterwards.

## 8. Deactivation safety

- [ ] Queue an email with SMTP broken (so it sits `Retrying`), then deactivate
      the plugin.
- [ ] Deactivation completes without error; Scheduled Actions has **no**
      pending `dpmq` actions left.
- [ ] Reactivate: recurring actions come back (step 1); any still-unsent rows
      get picked up by the watchdog within ~5 minutes.

## 9. Coexistence checks (per site)

- [ ] SMTP plugin (Post SMTP / WP Mail SMTP / FluentSMTP): its log shows sends
      happening seconds after the form response, and no health notice on the
      Mail Queue page.
- [ ] Caching (WP Rocket etc.): submit the form from a cached page — still
      queues and delivers.
- [ ] Anti-spam (CleanTalk): a submission it blocks produces **no** queued
      email (nothing to send).
- [ ] If WooCommerce is on the site (its own bundled Action Scheduler):
      Scheduled Actions still works, `dpmq` actions run, orders still email.

## 10. Overnight soak

- [ ] Next day: `dpmq_purge` and `dpmq_watchdog` show recent completed runs in
      Scheduled Actions; no `Failed` rows in the log that you can't explain;
      the "Delivery speed" info notice shows a sane average.
