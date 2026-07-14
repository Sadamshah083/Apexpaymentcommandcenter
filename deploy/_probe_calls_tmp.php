<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $calls = app(App\Services\Communications\MorpheusHubService::class)->activeCalls();
    echo 'count='.count($calls).PHP_EOL;
    echo substr(json_encode($calls), 0, 2500).PHP_EOL;

    $logs = App\Models\CommunicationCallLog::query()
        ->where('created_at', '>=', now()->subMinutes(30))
        ->orderByDesc('id')
        ->limit(10)
        ->get(['id', 'user_id', 'status', 'from_extension', 'to_phone', 'morpheus_call_uuid', 'created_at', 'direction']);
    echo 'recent_logs='.$logs->count().PHP_EOL;
    echo substr($logs->toJson(), 0, 2000).PHP_EOL;
} catch (Throwable $e) {
    echo 'ERR: '.$e->getMessage().PHP_EOL;
}
