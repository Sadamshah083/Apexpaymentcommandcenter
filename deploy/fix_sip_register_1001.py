#!/usr/bin/env python3
"""Fix WebRTC SIP: use extension 1001@apexone.pbx.local (verified 200 OK on Morpheus)."""

from __future__ import annotations

import base64
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PASSWORD = "b321e34632bf354633"
EXTENSION = "1001"
SIP_DOMAIN = "apexone.pbx.local"

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$webphone = app(App\Services\Communications\CommunicationsWebphoneService::class);
$click = app(App\Services\Communications\ZoomClickToCallService::class);
$extNum = __EXT__;
$password = __PASSWORD__;

$ext = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === $extNum) {
        $ext = $row;
        break;
    }
}

$extPatch = $ext && !empty($ext['id'])
    ? $zoom->updateExtension((string)$ext['id'], [
        'password' => $password,
        'status' => 'active',
        'is_dialer_agent' => true,
        'override_campaign_cid' => true,
    ])
    : null;

$userPatch = null;
if ($ext && !empty($ext['user_id'])) {
    $userPatch = $zoom->updateUser((string)$ext['user_id'], [
        'password' => $password,
        'status' => 'active',
    ]);
}

app(App\Services\Communications\MorpheusHubService::class)->bustCache();

$userModel = App\Models\User::where('name', 'admin_super_91a')->first() ?: App\Models\User::first();
$workspace = app(App\Services\Workspace\WorkspaceContextService::class)->resolveActiveWorkspace($userModel);
$config = $webphone->configFor($userModel, $workspace, $extNum, 'admin.');

echo json_encode([
    'ok' => true,
    'webrtc_domain' => $click->webrtcSipDomain(),
    'webphone' => $config,
    'ext_patch_ok' => $extPatch === null || !isset($extPatch['error']) || isset($extPatch['id']),
    'user_patch_ok' => $userPatch === null || !isset($userPatch['error']) || isset($userPatch['id']),
], JSON_PRETTY_PRINT);
"""


def patch_env_file(path: Path) -> None:
    text = path.read_text(encoding="utf-8")
    updates = {
        "MORPHEUS_EXTENSION_PASSWORD": PASSWORD,
        "MORPHEUS_WEBRTC_SIP_DOMAIN": SIP_DOMAIN,
        "MORPHEUS_SIP_HOST": SIP_DOMAIN,
        "MORPHEUS_SIP_AUTH_USER": "",
    }
    for key, val in updates.items():
        pattern = re.compile(r"^" + re.escape(key) + r"=.*$", re.M)
        if val == "":
            text = pattern.sub("", text) if pattern.search(text) else text
        else:
            line = f"{key}={val}"
            text = pattern.sub(line, text) if pattern.search(text) else text.rstrip() + "\n" + line + "\n"
    text = re.sub(r"\n{3,}", "\n\n", text)
    path.write_text(text, encoding="utf-8")


def patch_remote_env(ssh) -> None:
    from deploy._ssh import set_env_vars

    set_env_vars(
        ssh,
        {
            "MORPHEUS_EXTENSION_PASSWORD": PASSWORD,
            "MORPHEUS_WEBRTC_SIP_DOMAIN": SIP_DOMAIN,
            "MORPHEUS_SIP_HOST": SIP_DOMAIN,
        },
    )
    sudo_run(
        ssh,
        f"python3 -c \"import pathlib,re; p=pathlib.Path('{REMOTE_APP}/.env'); t=p.read_text(); t=re.sub(r'^MORPHEUS_SIP_AUTH_USER=.*\\\\n?','',t,flags=re.M); p.write_text(t)\"",
    )


def main() -> int:
    php = (
        PHP.replace("__EXT__", json.dumps(EXTENSION))
        .replace("__PASSWORD__", json.dumps(PASSWORD))
    )
    enc = base64.b64encode(php.encode()).decode()

    ssh = connect()
    patch_remote_env(ssh)
    sudo_run(ssh, f"cd {REMOTE_APP} && php artisan config:clear && php artisan config:cache")
    raw = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    ssh.close()

    patch_env_file(ROOT / ".env")
    print(raw)
    wp = json.loads(raw).get("webphone") or {}
    safe = {k: v for k, v in wp.items() if k != "password"}
    safe["password_length"] = len(str(wp.get("password") or ""))
    print(json.dumps(safe, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
