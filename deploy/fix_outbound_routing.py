#!/usr/bin/env python3
"""Assign outbound campaign to extension 1020 and verify Morpheus routing."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$campaignId = config('integrations.morpheus.default_campaign_id');
$extNum = '1020';

$ext = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === $extNum) {
        $ext = $row;
        break;
    }
}

if (!$ext || empty($ext['id'])) {
    echo json_encode(['ok' => false, 'error' => 'Extension 1020 not found']);
    exit(1);
}

$patch = $zoom->updateExtension((string)$ext['id'], array_filter([
    'campaign_id' => $campaignId,
    'is_dialer_agent' => true,
    'override_campaign_cid' => true,
    'status' => 'active',
]));

app(App\Services\Communications\MorpheusHubService::class)->bustCache();

$refreshed = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === $extNum) {
        $refreshed = $row;
        break;
    }
}

$agents = app(App\Services\Communications\CommunicationsAgentService::class);

echo json_encode([
    'ok' => !isset($patch['error']) || isset($patch['id']),
    'patch_error' => $patch['error'] ?? null,
    'campaign_id' => $campaignId,
    'extension' => $refreshed ? [
        'extension_num' => $refreshed['extension_num'] ?? null,
        'campaign_id' => $refreshed['campaign_id'] ?? null,
        'caller_id_num' => $refreshed['caller_id_num'] ?? null,
        'outbound_cid_num' => $refreshed['outbound_cid_num'] ?? null,
    ] : null,
    'dial_options' => $agents->extensionDialOptions($extNum),
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    raw = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    print(raw)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
