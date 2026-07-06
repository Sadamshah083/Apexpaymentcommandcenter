#!/usr/bin/env python3
"""Verify click-to-call payload and extension registration on production."""
import base64, json, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$ext = '1020';
$dial = $agents->extensionDialOptions($ext);
$dest = preg_replace('/\D/', '', config('integrations.communications.default_dial_destination') ?: '12722001232');
$payload = array_merge([
    'extension' => $ext,
    'destination' => $dest,
], array_filter([
    'caller_id_number' => $dial['caller_id_number'] ?? null,
    'campaign_id' => $dial['campaign_id'] ?? null,
], fn ($v) => filled($v)));
$online = $agents->extensionEndpointOnline($ext);
$registrations = [];
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === $ext) {
        $registrations[] = [
            'extension_num' => $row['extension_num'] ?? null,
            'status' => $row['status'] ?? null,
            'user_id' => $row['user_id'] ?? null,
            'outbound_cid_num' => $row['outbound_cid_num'] ?? null,
        ];
    }
}
echo json_encode([
    'click_to_call_payload' => $payload,
    'extension_online' => $online,
    'extension_rows' => $registrations,
    'auto_answer_env' => env('MORPHEUS_WEBPHONE_AUTO_ANSWER'),
    'default_outbound_did' => config('integrations.communications.default_outbound_did'),
], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
