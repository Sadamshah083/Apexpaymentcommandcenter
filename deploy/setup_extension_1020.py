#!/usr/bin/env python3
"""Configure extension 1020 for WebRTC outbound calling on production."""

from __future__ import annotations

import base64
import json
import re
import secrets
import string
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

EXTENSION = "1020"
DID = "13133851223"
DID_E164 = f"+{DID}"
SIP_DOMAIN = "apexone.pbx.local"


def make_password(length: int = 18) -> str:
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$webphone = app(App\Services\Communications\CommunicationsWebphoneService::class);

$extNum = __EXT__;
$did = __DID__;
$newPassword = __PASSWORD__;

$ext = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === $extNum) {
        $ext = $row;
        break;
    }
}

if (!$ext || empty($ext['id'])) {
    echo json_encode(['ok' => false, 'error' => "Extension {$extNum} not found in Morpheus"]);
    exit(1);
}

$user = null;
$userId = $ext['user_id'] ?? null;
if ($userId) {
    $user = $zoom->getUser((string)$userId);
}

$callerName = $ext['caller_id_name'] ?? ($user['first_name'] ?? 'Agent');

$extPatch = $zoom->updateExtension((string)$ext['id'], [
    'password' => $newPassword,
    'status' => 'active',
    'is_dialer_agent' => true,
    'override_campaign_cid' => true,
    'caller_id_num' => $did,
    'outbound_cid_num' => $did,
    'caller_id_name' => $callerName,
    'outbound_cid_name' => $callerName,
    'campaign_id' => config('integrations.morpheus.default_campaign_id'),
]);

$userPatch = null;
if ($userId) {
    $userPatch = $zoom->updateUser((string)$userId, [
        'password' => $newPassword,
        'status' => 'active',
    ]);
}

app(App\Services\Communications\MorpheusHubService::class)->bustCache();

$userModel = App\Models\User::where('name', 'admin_super_91a')->first() ?: App\Models\User::first();
$workspace = app(App\Services\Workspace\WorkspaceContextService::class)->resolveActiveWorkspace($userModel);
$config = $webphone->configFor($userModel, $workspace, $extNum, 'admin.');

echo json_encode([
    'ok' => true,
    'extension_id' => $ext['id'],
    'extension_num' => $ext['extension_num'] ?? null,
    'endpoint_online' => $ext['endpoint_online'] ?? null,
    'user_username' => $user['username'] ?? null,
    'extension_patch_ok' => !isset($extPatch['error']) || isset($extPatch['id']),
    'extension_patch_error' => $extPatch['error'] ?? null,
    'user_patch_ok' => $userPatch === null || !isset($userPatch['error']) || isset($userPatch['id']),
    'dial_options' => $agents->extensionDialOptions($extNum),
    'webphone' => $config ? [
        'extension' => $config['extension'] ?? null,
        'auth_user' => $config['auth_user'] ?? null,
        'sip_user' => $config['sip_user'] ?? null,
        'domain' => $config['domain'] ?? null,
        'dial_domain' => $config['dial_domain'] ?? null,
        'wss_url' => $config['wss_url'] ?? null,
        'outbound_caller_id' => $config['outbound_caller_id'] ?? null,
    ] : null,
], JSON_PRETTY_PRINT);
"""


def update_local_env(password: str) -> None:
    env_path = ROOT / ".env"
    if not env_path.is_file():
        return

    text = env_path.read_text(encoding="utf-8")
    updates = {
        "COMMUNICATIONS_DEFAULT_CALLER_ID": EXTENSION,
        "COMMUNICATIONS_DEFAULT_OUTBOUND_DID": DID_E164,
        "MORPHEUS_EXTENSION_PASSWORD": password,
        "MORPHEUS_WEBRTC_SIP_DOMAIN": SIP_DOMAIN,
    }

    for key, val in updates.items():
        pattern = re.compile(r"^" + re.escape(key) + r"=.*$", re.M)
        line = f"{key}={val}"
        text = pattern.sub(line, text) if pattern.search(text) else text.rstrip() + "\n" + line + "\n"

    if re.search(r"^MORPHEUS_SIP_AUTH_USER=", text, re.M):
        text = re.sub(r"^MORPHEUS_SIP_AUTH_USER=.*\n?", "", text, flags=re.M)

    env_path.write_text(text, encoding="utf-8")


def main() -> int:
    password = make_password()
    print(f"Configuring Morpheus extension {EXTENSION} with DID {DID_E164}")

    php = (
        PHP.replace("__EXT__", json.dumps(EXTENSION))
        .replace("__DID__", json.dumps(DID))
        .replace("__PASSWORD__", json.dumps(password))
    )
    encoded = base64.b64encode(php.encode()).decode()

    ssh = connect()
    raw = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php",
    )
    print(raw)

    result = json.loads(raw)
    if not result.get("ok"):
        print("FAILED:", result.get("error"))
        ssh.close()
        return 1

    set_env_vars(
        ssh,
        {
            "COMMUNICATIONS_DEFAULT_CALLER_ID": EXTENSION,
            "COMMUNICATIONS_DEFAULT_OUTBOUND_DID": DID_E164,
            "MORPHEUS_EXTENSION_PASSWORD": password,
            "MORPHEUS_WEBRTC_SIP_DOMAIN": SIP_DOMAIN,
            "MORPHEUS_WEBPHONE_AUTO_ANSWER": "true",
        },
    )

    sudo_run(
        ssh,
        " && ".join(
            [
                f"cd {REMOTE_APP}",
                "python3 -c \"import pathlib,re; p=pathlib.Path('.env'); t=p.read_text(); "
                "t=re.sub(r'^MORPHEUS_SIP_AUTH_USER=.*\\\\n?','',t,flags=re.M); p.write_text(t)\"",
                "php artisan config:clear",
                "php artisan config:cache",
                "php artisan view:clear",
            ]
        ),
    )

    update_local_env(password)
    print("Production + local .env updated for extension 1020")
    print(json.dumps({"dial_options": result.get("dial_options"), "webphone": result.get("webphone")}, indent=2))

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
