#!/usr/bin/env python3
"""Configure Morpheus WebRTC SIP (apexone.pbx.local + babar) on production."""

from __future__ import annotations

import base64
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

AUTH_USER = "babar"
PASSWORD = "b321e34632bf354633"
SIP_DOMAIN = "apexone.pbx.local"
EXTENSION = "1001"

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$click = app(App\Services\Communications\ZoomClickToCallService::class);
$webphone = app(App\Services\Communications\CommunicationsWebphoneService::class);

$authUser = __AUTH__;
$password = __PASSWORD__;
$extNum = __EXT__;

$ext = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === $extNum) {
        $ext = $row;
        break;
    }
}

$mUser = null;
foreach ($zoom->listUsers(['limit' => 500, 'search' => $authUser])['users'] ?? [] as $row) {
    if (strcasecmp((string)($row['username'] ?? ''), $authUser) === 0) {
        $mUser = $zoom->getUser((string)$row['id']) ?? $row;
        break;
    }
}

$extPatch = null;
if ($ext && !empty($ext['id'])) {
    $extPatch = $zoom->updateExtension((string)$ext['id'], [
        'password' => $password,
        'status' => 'active',
        'is_dialer_agent' => true,
        'override_campaign_cid' => true,
    ]);
}

$userPatch = null;
if ($mUser && !empty($mUser['id'])) {
    $userPatch = $zoom->updateUser((string)$mUser['id'], [
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
    'public_host' => $click->publicSipHost(),
    'morpheus_user' => $mUser ? [
        'id' => $mUser['id'] ?? null,
        'username' => $mUser['username'] ?? null,
        'status' => $mUser['status'] ?? null,
    ] : null,
    'extension' => $ext ? [
        'extension_num' => $ext['extension_num'] ?? null,
        'status' => $ext['status'] ?? null,
    ] : null,
    'ext_patch_ok' => $extPatch === null || !isset($extPatch['error']) || isset($extPatch['id']),
    'user_patch_ok' => $userPatch === null || !isset($userPatch['error']) || isset($userPatch['id']),
    'webphone' => $config,
], JSON_PRETTY_PRINT);
"""


def update_local_env() -> None:
    path = ROOT / ".env"
    text = path.read_text(encoding="utf-8")
    updates = {
        "MORPHEUS_SIP_AUTH_USER": AUTH_USER,
        "MORPHEUS_EXTENSION_PASSWORD": PASSWORD,
        "MORPHEUS_WEBRTC_SIP_DOMAIN": SIP_DOMAIN,
        "MORPHEUS_SIP_HOST": SIP_DOMAIN,
    }
    for key, val in updates.items():
        pattern = re.compile(r"^" + re.escape(key) + r"=.*$", re.M)
        line = f"{key}={val}"
        text = pattern.sub(line, text) if pattern.search(text) else text.rstrip() + "\n" + line + "\n"
    path.write_text(text, encoding="utf-8")


def main() -> int:
    php = (
        PHP.replace("__AUTH__", json.dumps(AUTH_USER))
        .replace("__PASSWORD__", json.dumps(PASSWORD))
        .replace("__EXT__", json.dumps(EXTENSION))
    )
    encoded = base64.b64encode(php.encode()).decode()

    ssh = connect()
    set_env_vars(
        ssh,
        {
            "MORPHEUS_SIP_AUTH_USER": AUTH_USER,
            "MORPHEUS_EXTENSION_PASSWORD": PASSWORD,
            "MORPHEUS_WEBRTC_SIP_DOMAIN": SIP_DOMAIN,
            "MORPHEUS_SIP_HOST": SIP_DOMAIN,
        },
    )
    print("Production .env updated (babar + apexone.pbx.local)")

    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && php artisan config:clear && php artisan config:cache",
    )

    raw = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php",
    )
    print(raw)
    ssh.close()

    update_local_env()
    print("Local .env updated")

    result = json.loads(raw)
    wp = result.get("webphone") or {}
    safe = {
        k: v for k, v in wp.items() if k != "password"
    }
    safe["password_length"] = len(str(wp.get("password") or ""))
    print(json.dumps({"webrtc_domain": result.get("webrtc_domain"), "webphone": safe}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
