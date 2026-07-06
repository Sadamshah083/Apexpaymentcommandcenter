#!/usr/bin/env python3
"""Set outbound/inbound DID on Morpheus + CRM, reset SIP password, refresh config."""

from __future__ import annotations

import base64
import json
import secrets
import string
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

EXTENSION = "1001"
DID = "13133851223"
DID_E164 = f"+{DID}"


def make_password(length: int = 16) -> str:
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
    echo json_encode(['ok' => false, 'error' => 'Extension not found']);
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
    'user_username' => $user['username'] ?? null,
    'extension_patch_ok' => !isset($extPatch['error']) || isset($extPatch['id']),
    'extension_patch_error' => $extPatch['error'] ?? null,
    'user_patch_ok' => $userPatch === null || !isset($userPatch['error']) || isset($userPatch['id']),
    'dial_options' => $agents->extensionDialOptions($extNum),
    'webphone' => $config ? [
        'auth_user' => $config['auth_user'] ?? null,
        'domain' => $config['domain'] ?? null,
        'wss_url' => $config['wss_url'] ?? null,
        'password_length' => strlen((string)($config['password'] ?? '')),
    ] : null,
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    password = make_password()
    print(f"Setting DID {DID_E164} on extension {EXTENSION}")
    print(f"Resetting SIP password ({len(password)} chars)")

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
    print("=== Morpheus + webphone config ===")
    print(raw)

    result = json.loads(raw)
    if not result.get("ok"):
        print("FAILED:", result.get("error"))
        ssh.close()
        return 1

    set_env_vars(
        ssh,
        {
            "COMMUNICATIONS_DEFAULT_OUTBOUND_DID": DID_E164,
            "MORPHEUS_EXTENSION_PASSWORD": password,
        },
    )
    print("Updated production .env (DID + SIP password)")

    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && php artisan config:clear && php artisan config:cache && php artisan view:clear",
    )
    print("Config + view cache refreshed")

    ssh.close()

    # Local .env DID
    env_path = ROOT / ".env"
    text = env_path.read_text(encoding="utf-8")
    import re

    for key, val in {
        "COMMUNICATIONS_DEFAULT_OUTBOUND_DID": DID_E164,
        "MORPHEUS_EXTENSION_PASSWORD": password,
    }.items():
        pattern = re.compile(r"^" + re.escape(key) + r"=.*$", re.M)
        line = f"{key}={val}"
        text = pattern.sub(line, text) if pattern.search(text) else text.rstrip() + "\n" + line + "\n"
    env_path.write_text(text, encoding="utf-8")
    print("Local .env updated")

    print(json.dumps({"dial_options": result.get("dial_options"), "webphone": result.get("webphone")}, indent=2))
    print("Done — hard refresh CRM and click Connect line.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
