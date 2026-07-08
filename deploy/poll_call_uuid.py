#!/usr/bin/env python3
import base64, json, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = sys.argv[1] if len(sys.argv) > 1 else ""
if not UUID:
    raise SystemExit("Usage: poll_call_uuid.py <uuid>")

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$host = config('integrations.morpheus.host');
$key = config('integrations.morpheus.api_key');
$base = "https://{$host}/api/v1/call-control";
$client = fn() => Illuminate\Support\Facades\Http::timeout(12)->acceptJson()->withHeaders([
    'X-API-Key' => $key,
    'Authorization' => 'Bearer ' . $key,
]);
$uuid = '__UUID__';
$poll = [];
for ($i = 0; $i < 8; $i++) {
    $r = $client()->get("$base/calls/$uuid");
    $poll[] = ['t' => $i, 'status' => $r->status(), 'body' => $r->json()];
    usleep(750000);
}
$cdr = $client()->get("$base/cdr", ['limit' => 20]);
$rows = array_values(array_filter($cdr->json('cdr') ?? [], fn ($r) => ($r['call_uuid'] ?? '') === $uuid || ($r['uuid'] ?? '') === $uuid));
$zoom = app(App\Services\Integrations\ZoomApiService::class);
echo json_encode(['poll_get_call' => $poll, 'cdr_matches' => $rows, 'laravel_get' => $zoom->getCall($uuid)], JSON_PRETTY_PRINT);
""".replace('__UUID__', UUID)

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
