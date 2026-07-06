#!/usr/bin/env python3
"""Diagnose recent outbound calls from extension 1020 — CDR hangup causes."""

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
$dest = preg_replace('/\D/', '', (string) config('integrations.communications.default_dial_destination', '12722001232'));

$calls = $zoom->listCalls([
    'limit' => 30,
    'sort' => '-start_time',
])['calls'] ?? [];

$filtered = [];
foreach ($calls as $row) {
    $to = preg_replace('/\D/', '', (string) ($row['to'] ?? $row['destination'] ?? ''));
    $from = (string) ($row['from'] ?? $row['extension'] ?? $row['caller_id_num'] ?? '');
    if (str_contains($to, $dest) || str_contains($from, '1020') || str_contains((string)($row['extension_num'] ?? ''), '1020')) {
        $filtered[] = [
            'uuid' => $row['uuid'] ?? $row['id'] ?? null,
            'start' => $row['start_time'] ?? $row['created_at'] ?? null,
            'from' => $row['from'] ?? $row['extension_num'] ?? null,
            'to' => $row['to'] ?? $row['destination'] ?? null,
            'duration' => $row['duration'] ?? $row['billsec'] ?? null,
            'talk_time' => $row['talk_time'] ?? null,
            'hangup_cause' => $row['hangup_cause'] ?? null,
            'hangup_by' => $row['hangup_by'] ?? null,
            'direction' => $row['direction'] ?? null,
            'status' => $row['status'] ?? $row['disposition'] ?? null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'caller_id' => $row['caller_id_num'] ?? $row['outbound_cid_num'] ?? null,
        ];
    }
}

$ext = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === '1020') {
        $ext = [
            'id' => $row['id'] ?? null,
            'extension_num' => $row['extension_num'] ?? null,
            'status' => $row['status'] ?? null,
            'is_dialer_agent' => $row['is_dialer_agent'] ?? null,
            'caller_id_num' => $row['caller_id_num'] ?? null,
            'outbound_cid_num' => $row['outbound_cid_num'] ?? null,
            'override_campaign_cid' => $row['override_campaign_cid'] ?? null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'endpoint_online' => $row['endpoint_online'] ?? null,
        ];
        break;
    }
}

$campaignId = config('integrations.morpheus.default_campaign_id');
$campaign = $campaignId ? $zoom->getCampaign((string) $campaignId) : null;

$cdr = $zoom->listCdr(['limit' => 25, 'search' => $dest]);
$cdrAll = $zoom->listCdr(['limit' => 25]);

$pick = function(array $row) {
    $raw = $row['raw'] ?? $row;
    return [
        'id' => $row['id'] ?? null,
        'start' => $row['start_time'] ?? null,
        'from' => $row['from_phone'] ?? $row['from'] ?? null,
        'to' => $row['to_phone'] ?? $row['to'] ?? null,
        'duration' => $row['duration'] ?? null,
        'result' => $row['result'] ?? null,
        'hangup_cause' => $raw['hangup_cause'] ?? null,
        'hangup_by' => $raw['hangup_by'] ?? null,
        'direction' => $row['direction'] ?? null,
        'campaign_id' => $row['campaign_id'] ?? null,
        'sip_code' => $raw['sip_hangup_cause'] ?? $raw['sip_code'] ?? null,
    ];
};

echo json_encode([
    'destination_digits' => $dest,
    'extension_1020' => $ext,
    'campaign' => $campaign ? [
        'id' => $campaign['id'] ?? null,
        'name' => $campaign['name'] ?? null,
        'status' => $campaign['status'] ?? null,
        'dial_mode' => $campaign['dial_mode'] ?? null,
        'caller_id_num' => $campaign['caller_id_num'] ?? null,
        'outbound_route' => $campaign['outbound_route'] ?? $campaign['route_id'] ?? null,
    ] : null,
    'recent_calls' => array_map($pick, array_slice($cdr['logs'] ?? [], 0, 15)),
    'recent_any' => array_map($pick, array_slice($cdrAll['logs'] ?? [], 0, 8)),
    'cdr_warning' => $cdr['warning'] ?? null,
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
