#!/usr/bin/env python3
"""Verify CRM loads latest webphone bundle + config."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use App\Services\Communications\CommunicationsWebphoneService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Support\Facades\Auth;

$manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
$entry = $manifest['resources/js/communications-webphone.js']['file'] ?? '?';
echo "BUNDLE={$entry}\n";
echo "WSS=".config('integrations.morpheus.sip_wss_url')."\n";
echo "DOMAIN=".config('integrations.morpheus.webrtc_sip_domain')."\n";
echo "DIAL_MODE=".config('integrations.morpheus.webphone_dial_mode')."\n";

$user = User::query()->whereHas('roles')->orWhere('id', '>', 0)->orderBy('id')->first();
if ($user) {
    Auth::login($user);
    $ws = app(WorkspaceContextService::class)->resolveActiveWorkspace($user);
    $cfg = app(CommunicationsWebphoneService::class)->configFor($user, $ws, '1020', '');
    echo "CONFIG_EXT=".($cfg['extension'] ?? 'null')."\n";
    echo "CONFIG_WSS=".($cfg['wss_url'] ?? 'null')."\n";
    echo "CONFIG_DOMAIN=".($cfg['domain'] ?? 'null')."\n";
    echo "CONFIG_DIAL_MODE=".($cfg['dial_mode'] ?? 'null')."\n";
}
"""

def main() -> int:
    ssh = connect()
    tmp = "/tmp/verify-crm-webphone.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
    print(sudo_run(ssh, f"grep -o 'reasonPhrase: .OK' {REMOTE_APP}/public/build/assets/communications-webphone-*.js 2>/dev/null | head -1 || echo NOTIFY_FIX=check_manually", check=False))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
