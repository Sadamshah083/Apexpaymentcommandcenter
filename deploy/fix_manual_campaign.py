#!/usr/bin/env python3
"""Fix campaign dial_mode=manual + test originate with lead."""
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$campaignId = config('integrations.morpheus.default_campaign_id');
$dest = '12722001232';

echo "1) PATCH campaign to manual dial_mode...\n";
$patched = $api->updateCampaign((string)$campaignId, [
    'dial_mode' => 'manual',
    'status' => 'active',
    'require_disposition' => false,
]);
echo json_encode($patched, JSON_PRETTY_PRINT)."\n";

echo "\n2) Ensure lead list + lead for {$dest}...\n";
$lists = $api->listLeadLists(['limit' => 50])['lists'] ?? [];
$listId = null;
foreach ($lists as $list) {
    if (($list['name'] ?? '') === 'Hub Test Calls') {
        $listId = $list['id'];
        break;
    }
}
if (!$listId) {
    $created = $api->createLeadList(['name' => 'Hub Test Calls', 'status' => 'active', 'campaign_id' => $campaignId]);
    $listId = $created['id'] ?? null;
    echo "Created list: ".json_encode($created)."\n";
}
$leadId = null;
foreach ($api->listLeads(['limit' => 50, 'search' => $dest])['leads'] ?? [] as $lead) {
    if (str_contains(preg_replace('/\D/', '', $lead['phone_number'] ?? ''), $dest)) {
        $leadId = $lead['id'];
        break;
    }
}
if (!$leadId && $listId) {
    $createdLead = $api->createLead([
        'phone_number' => '+12722001232',
        'list_id' => $listId,
        'first_name' => 'Hub',
        'last_name' => 'Test',
        'status' => 'clean',
    ]);
    $leadId = $createdLead['id'] ?? null;
    echo "Created lead: ".json_encode($createdLead)."\n";
}
echo "listId={$listId} leadId={$leadId}\n";

echo "\n3) Release stale active calls...\n";
echo json_encode($api->releaseStaleActiveCalls(0))."\n";

echo "\n4) Originate ext 1020 with lead_id...\n";
$opts = $agents->extensionDialOptions('1020');
if ($leadId) $opts['lead_id'] = $leadId;
$result = $api->originateCall('1020', '+12722001232', $opts);
echo json_encode($result, JSON_PRETTY_PRINT)."\n";
$uuid = $result['call_uuid'] ?? null;
if ($uuid) {
    sleep(16);
    echo "\n5) CDR snapshot:\n";
    echo json_encode($api->getCall($uuid), JSON_PRETTY_PRINT)."\n";
}

echo "\n6) Campaign after patch:\n";
echo json_encode($api->getCampaign((string)$campaignId), JSON_PRETTY_PRINT)."\n";
"""

ssh = connect()
tmp = "/tmp/fix-manual-campaign.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False)
print(out.encode("ascii", errors="replace").decode("ascii"))
ssh.close()
