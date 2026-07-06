#!/usr/bin/env python3
"""Verify webphone config after SIP password sync (no secrets in output)."""

from __future__ import annotations

import base64
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()->where('email', 'like', '%')->orderBy('id')->first();
$workspace = app(App\Services\Workspace\WorkspaceContextService::class)->resolveActiveWorkspace($user);
$webphone = app(App\Services\Communications\CommunicationsWebphoneService::class);
$config = $webphone->configFor($user, $workspace, '1001', '');

if (!$config) {
    echo json_encode(['ok' => false, 'error' => 'no config']);
    exit(0);
}

echo json_encode([
    'ok' => true,
    'extension' => $config['extension'] ?? null,
    'auth_user' => $config['auth_user'] ?? null,
    'domain' => $config['domain'] ?? null,
    'wss_url' => $config['wss_url'] ?? null,
    'wss_url_fallback' => $config['wss_url_fallback'] ?? null,
    'password_length' => strlen((string)($config['password'] ?? '')),
    'password_nonempty' => filled($config['password'] ?? null),
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = connect()
    encoded = base64.b64encode(PHP.encode()).decode()
    raw = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php",
    )
    print(raw)

    # Confirm .env key exists without printing value
    env_line = sudo_run(ssh, f"grep -E '^MORPHEUS_EXTENSION_PASSWORD=' {REMOTE_APP}/.env | wc -c")
    print(f"MORPHEUS_EXTENSION_PASSWORD line length (incl newline): {env_line.strip()}")

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
