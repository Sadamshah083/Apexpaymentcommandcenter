<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateWebPushVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate VAPID keys for browser push notifications';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $exception) {
            $this->warn('PHP could not generate EC keys on this machine ('.$exception->getMessage().').');
            $this->line('Run: npx web-push generate-vapid-keys');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('Add these lines to your .env file:');
        $this->newLine();
        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('WEBPUSH_SUBJECT=mailto:'.config('mail.from.address', 'admin@example.com'));
        $this->newLine();
        $this->comment('Restart the app server after updating .env, then click the bell icon to enable system notifications.');

        return self::SUCCESS;
    }
}
