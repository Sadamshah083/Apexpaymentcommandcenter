<?php

namespace App\Jobs;

use App\Models\DisposableDomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class SyncDisposableDomainsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $url = config('email_checker.disposable.blocklist_url');
        $request = Http::timeout(120);

        if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
            $request = $request->withoutVerifying();
        }

        $response = $request->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download disposable domain blocklist');
        }

        $domains = array_filter(array_map('trim', explode("\n", $response->body())));
        $now = now();
        $chunks = array_chunk($domains, 500);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $domain) {
                $domain = strtolower($domain);
                if ($domain === '' || str_starts_with($domain, '#')) {
                    continue;
                }

                DisposableDomain::updateOrCreate(
                    ['domain' => $domain],
                    ['synced_at' => $now]
                );
            }
        }
    }
}
