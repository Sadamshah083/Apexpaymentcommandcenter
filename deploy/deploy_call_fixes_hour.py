#!/usr/bin/env python3
"""Deploy past-hour call fixes + sync Morpheus extension caller ID names."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Support/MorpheusSipIdentity.php",
    "app/Services/Integrations/ZoomApiService.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "resources/js/communications-dialer.js",
    "resources/js/communications-webphone.js",
]

FIX_EXT_PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http;
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$did = preg_replace('/\D/', '', (string) config('integrations.communications.default_outbound_did'));
$resp = Http::withHeaders(['X-API-Key' => $key])->get("https://{$host}/api/v1/call-control/extensions", ['limit' => 200]);
$fixed = 0;
foreach ($resp->json('extensions') ?? [] as $ext) {
    $id = $ext['id'] ?? null;
    $num = $ext['extension_num'] ?? '';
    if (!$id) continue;
    $name = $ext['caller_id_name'] ?? '';
    if ($name === $did || $name === '' || $name === '13133851223') continue;
    if (!preg_match('/^(admin|setter|closer)_(super|ops|tl|ag)_/i', (string) $name)) continue;
    $patch = Http::withHeaders(['X-API-Key' => $key])->patch("https://{$host}/api/v1/call-control/extensions/{$id}", [
        'caller_id_name' => $did,
        'outbound_cid_name' => $did,
        'caller_id_num' => $did,
        'outbound_cid_num' => $did,
    ]);
    if ($patch->successful()) {
        echo "fixed ext {$num} caller_id_name from {$name}\n";
        $fixed++;
    }
}
echo "done fixed={$fixed}\n";
"""


def main() -> int:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    tmp = "/tmp/fix-ext-cid.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f:
        f.write(FIX_EXT_PHP)
    sftp.close()
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build",
        f"cd {REMOTE_APP} && sudo -u www-data php {tmp}",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        "systemctl reload php8.3-fpm 2>/dev/null || true",
    ])
    ssh.close()
    print("Deploy complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
