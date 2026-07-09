#!/usr/bin/env python3
"""One-shot quick ring via raw Morpheus API."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = sys.argv[1] if len(sys.argv) > 1 else "1007"
PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{{$host}}/api/v1/call-control";
$r = Illuminate\\Support\\Facades\\Http::timeout(15)->acceptJson()->withHeaders(['X-API-Key' => $key])
    ->post("$base/click-to-call", [
        'extension' => '{EXT}',
        'destination' => '12722001232',
        'timeout_sec' => 60,
        'campaign_id' => config('integrations.morpheus.default_campaign_id'),
        'caller_id_number' => '13133851223',
    ]);
echo $r->status()." ".$r->body()."\\n";
$uuid = $r->json('call_uuid');
if (!$uuid) exit(0);
$api = app(App\\Services\\Integrations\\ZoomApiService::class);
for ($i = 0; $i < 15; $i++) {{
    sleep(1);
    $s = $api->getCall($uuid) ?? [];
    echo "t=".($i+1)." live=".json_encode($s['live']??false)." bill=".($s['billsec']??0)." cause=".($s['hangup_cause']??'-')."\\n";
}}
$api->hangup($uuid);
"""

def main() -> int:
    ssh = connect(timeout=25)
    tmp = "/tmp/quick-ring.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && timeout 45 sudo -u www-data php {tmp}", check=False))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
