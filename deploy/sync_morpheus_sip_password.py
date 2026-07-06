#!/usr/bin/env python3
"""Reset Morpheus extension SIP password, sync .env, and clear Laravel config cache."""

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


def make_password(length: int = 16) -> str:
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


PHP_TEMPLATE = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$extNum = __EXT__;
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

$extPatch = $zoom->updateExtension((string)$ext['id'], [
    'password' => $newPassword,
    'status' => 'active',
    'is_dialer_agent' => true,
    'override_campaign_cid' => true,
]);

$userPatch = null;
if ($userId) {
    $userPatch = $zoom->updateUser((string)$userId, [
        'password' => $newPassword,
        'status' => 'active',
    ]);
}

app(App\Services\Communications\MorpheusHubService::class)->bustCache();

echo json_encode([
    'ok' => true,
    'extension_id' => $ext['id'],
    'extension_num' => $ext['extension_num'] ?? null,
    'extension_status' => $ext['status'] ?? null,
    'user_id' => $userId,
    'user_username' => $user['username'] ?? null,
    'user_status' => $user['status'] ?? null,
    'extension_patch_ok' => !isset($extPatch['error']) || isset($extPatch['id']),
    'extension_patch_error' => $extPatch['error'] ?? null,
    'user_patch_ok' => $userPatch === null || !isset($userPatch['error']) || isset($userPatch['id']),
    'user_patch_error' => $userPatch['error'] ?? null,
    'password_length' => strlen($newPassword),
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    password = make_password()
    print(f"Generated new SIP password ({len(password)} chars, alphanumeric)")

    php = (
        PHP_TEMPLATE.replace("__EXT__", json.dumps(EXTENSION))
        .replace("__PASSWORD__", json.dumps(password))
    )
    encoded = base64.b64encode(php.encode()).decode()

    ssh = connect()
    raw = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php",
    )
    print("=== Morpheus password sync ===")
    print(raw)

    result = json.loads(raw)
    if not result.get("ok"):
        print("FAILED:", result.get("error"))
        ssh.close()
        return 1

    if not result.get("extension_patch_ok"):
        print("Extension patch failed:", result.get("extension_patch_error"))
        ssh.close()
        return 1

    set_env_vars(ssh, {"MORPHEUS_EXTENSION_PASSWORD": password})
    print("Updated MORPHEUS_EXTENSION_PASSWORD in .env")

    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && php artisan config:clear && php artisan config:cache",
    )
    print("Laravel config cache refreshed")

    print(
        json.dumps(
            {
                "extension": result.get("extension_num"),
                "auth_username_hint": result.get("user_username") or EXTENSION,
                "user_patch_ok": result.get("user_patch_ok"),
            },
            indent=2,
        )
    )

    ssh.close()
    print("Done. Hard-refresh the CRM and click Connect line again.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
