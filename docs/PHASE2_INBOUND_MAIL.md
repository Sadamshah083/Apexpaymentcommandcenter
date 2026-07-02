# Email Checker - Phase 2 Inbound Mail Setup

The inbound test mailbox feature (mail-tester.com send-and-analyze) requires receiving email on a real domain.

## Requirements

1. **Domain with inbound mail** — Laragon cannot receive email locally.
2. **Catch-all or alias routing** — Route `test-*@yourdomain.com` to an IMAP mailbox.
3. **IMAP credentials** — Configure in `.env`:

```env
EMAIL_CHECKER_INBOUND_DOMAIN=yourdomain.com
EMAIL_CHECKER_IMAP_HOST=imap.yourdomain.com
EMAIL_CHECKER_IMAP_PORT=993
EMAIL_CHECKER_IMAP_USERNAME=tests@yourdomain.com
EMAIL_CHECKER_IMAP_PASSWORD=your-password
```

4. **Queue worker + scheduler** running:
    - `php artisan queue:work database`
    - `php artisan schedule:work` (or Windows Task Scheduler for `schedule:run`)

## How it works

1. User clicks "Generate Test Inbox" → unique address like `test-{uuid}@yourdomain.com`
2. User sends campaign email from their mail server to that address
3. `PollInboundMailboxJob` (scheduled every 5 min) connects via IMAP
4. Parses Authentication-Results, SPF, DKIM, DMARC from headers
5. Runs content analyzer on body
6. Produces full deliverability report

## Implementation status

`PollInboundMailboxJob` is a stub that expires old inboxes. Full IMAP parsing is Phase 2.

To implement: install `webklex/php-imap` and extend `PollInboundMailboxJob`.
