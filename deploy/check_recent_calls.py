#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\CommunicationCallLog;
use App\Services\Integrations\ZoomApiService;
$api = app(ZoomApiService::class);
echo "RECENT CALL LOGS (to_phone):\n";
foreach (CommunicationCallLog::orderByDesc('id')->limit(12)->get() as $r) {
    echo "  id={$r->id} ext={$r->from_extension} to_phone=".($r->to_phone ?: 'EMPTY')." uuid=".($r->morpheus_call_uuid ?: 'NULL')." {$r->created_at}\n";
    if ($r->morpheus_call_uuid) {
        $cdr = $api->getCall($r->morpheus_call_uuid);
        if ($cdr) {
            echo "    cdr dest=".($cdr['destination_number'] ?? '?')." outcome=".($cdr['call_outcome'] ?? '?')." cause=".($cdr['hangup_cause'] ?? '?')." billsec=".($cdr['billsec'] ?? 0)."\n";
        }
    }
}
try {
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => config('integrations.morpheus.api_key')])
        ->timeout(10)->get('https://'.config('integrations.morpheus.host').'/api/v1/call-control/audit', ['limit' => 8]);
    echo "\nCALL CONTROL AUDIT (last 8):\n";
    if ($r->successful()) {
        foreach ($r->json('audit') ?? $r->json('entries') ?? $r->json() ?? [] as $row) {
            if (!is_array($row)) continue;
            echo '  '.json_encode($row)."\n";
        }
    } else {
        echo '  HTTP '.$r->status().' '.$r->body()."\n";
    }
} catch (Throwable $e) {
    echo 'audit err: '.$e->getMessage()."\n";
}
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
echo "\nEXTENSION ONLINE STATUS:\n";
foreach (['1001','1016','1017','1018','1019','1020'] as $ext) {
    $online = $agents->extensionEndpointOnline($ext) ? 'online' : 'OFFLINE';
    echo "  ext {$ext}: {$online}\n";
}
"""
ssh = connect()
tmp = "/tmp/check-calls.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
