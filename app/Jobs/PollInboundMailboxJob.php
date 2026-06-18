<?php

namespace App\Jobs;

use App\Models\InboundTestInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 stub: Poll IMAP inbox for test emails sent to unique test addresses.
 *
 * Setup requirements:
 * - Configure EMAIL_CHECKER_INBOUND_DOMAIN with a real domain
 * - Set up catch-all or unique aliases pointing to IMAP mailbox
 * - Configure EMAIL_CHECKER_IMAP_HOST, PORT, USERNAME, PASSWORD
 * - Run: php artisan queue:work (this job should be scheduled every minute)
 */
class PollInboundMailboxJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $host = config('email_checker.inbound.imap_host');

        if (! $host) {
            Log::info('Inbound mailbox polling skipped: IMAP not configured. See docs for Phase 2 setup.');

            return;
        }

        // Phase 2 implementation placeholder:
        // 1. Connect to IMAP via PHP imap extension or webklex/php-imap
        // 2. Match messages to InboundTestInbox by recipient address
        // 3. Parse Authentication-Results, SPF, DKIM, DMARC headers
        // 4. Run ContentRuleEngine on body
        // 5. Update inbox status to 'analyzed'

        InboundTestInbox::where('status', 'waiting')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }
}
