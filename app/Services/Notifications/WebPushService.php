<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function isConfigured(): bool
    {
        return filled(config('webpush.public_key')) && filled(config('webpush.private_key'));
    }

    public function publicKey(): ?string
    {
        $key = config('webpush.public_key');

        return filled($key) ? $key : null;
    }

    public function sendToUser(User $user, string $title, string $body, ?string $url = null): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $subscriptions = $user->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'url' => $url ?? url('/'),
                'tag' => 'workspace-'.substr(md5($title.$body.$url), 0, 12),
            ]);

            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('webpush.subject'),
                    'publicKey' => config('webpush.public_key'),
                    'privateKey' => config('webpush.private_key'),
                ],
            ]);

            foreach ($subscriptions as $subscription) {
                if (! $subscription->public_key || ! $subscription->auth_token) {
                    continue;
                }

                try {
                    $webPush->queueNotification(
                        Subscription::create([
                            'endpoint' => $subscription->endpoint,
                            'keys' => [
                                'p256dh' => $subscription->public_key,
                                'auth' => $subscription->auth_token,
                            ],
                        ]),
                        $payload
                    );
                } catch (\Throwable $exception) {
                    Log::debug('Invalid push subscription skipped', [
                        'endpoint' => $subscription->endpoint,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }

                Log::debug('Web Push delivery failed', [
                    'endpoint' => $report->getEndpoint(),
                    'reason' => $report->getReason(),
                ]);

                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (\Throwable $exception) {
            Log::debug('Web Push unavailable on this machine', ['error' => $exception->getMessage()]);
        }
    }
}
