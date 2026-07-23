import os, sys, base64, json, shlex
from pathlib import Path
ROOT = Path(r"C:\Users\dev\Desktop\ApexonecommandCenter")
sys.path.insert(0, str(ROOT))
os.environ["DEPLOY_HOST"] = "203.215.161.236"
os.environ["DEPLOY_USER"] = "ateg"
os.environ["DEPLOY_PASSWORD"] = "balitech1"
import deploy._ssh as m
m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$billing = config("morpheus_billing_dids.extensions", []);

$extRows = $zoom->listExtensions(["limit" => 500])["extensions"] ?? [];
$byExt = [];
$usedDids = [];
foreach ($extRows as $e) {
    if (!is_array($e)) continue;
    $num = (string) ($e["extension_num"] ?? "");
    $did = preg_replace("/\D/", "", (string) ($e["caller_id_num"] ?? $e["outbound_cid_num"] ?? ""));
    $status = (string) ($e["status"] ?? "");
    $name = (string) ($e["name"] ?? $e["display_name"] ?? "");
    $byExt[$num] = [
        "extension" => $num,
        "did" => $did,
        "status" => $status,
        "name" => $name,
        "billing_did" => isset($billing[$num]) ? preg_replace("/\D/", "", (string) $billing[$num]) : null,
    ];
    if ($did !== "") {
        $usedDids[$did] = $num;
    }
}

// Users with sip/extension in DB
$users = [];
try {
    $q = \App\Models\User::query()->orderBy("id")->get(["id","name","email","sip_extension","morpheus_extension","phone_extension"]);
} catch (\Throwable $ex) {
    $q = \App\Models\User::query()->orderBy("id")->limit(200)->get();
}
foreach ($q as $u) {
    $attrs = $u->getAttributes();
    $ext = (string) ($attrs["sip_extension"] ?? $attrs["morpheus_extension"] ?? $attrs["phone_extension"] ?? $attrs["extension"] ?? "");
    $users[] = [
        "id" => $u->id,
        "name" => $u->name,
        "email" => $u->email,
        "extension" => preg_replace("/\D/", "", $ext),
    ];
}

// Also check workspace_user / any settings JSON for extensions
$assignedExts = [];
foreach ($users as $u) {
    if ($u["extension"] !== "") {
        $assignedExts[$u["extension"]] = $u["email"] ?: $u["name"];
    }
}

$pool = [];
foreach ($billing as $ext => $did) {
    $did = preg_replace("/\D/", "", (string) $did);
    $live = $byExt[(string) $ext] ?? null;
    $pool[] = [
        "extension" => (string) $ext,
        "did" => $did,
        "live_did" => $live["did"] ?? null,
        "ext_name" => $live["name"] ?? null,
        "ext_status" => $live["status"] ?? "missing",
        "assigned_to_user" => $assignedExts[(string) $ext] ?? null,
        "free" => empty($assignedExts[(string) $ext]),
    ];
}

$free = array_values(array_filter($pool, fn($r) => $r["free"]));
$busy = array_values(array_filter($pool, fn($r) => !$r["free"]));

echo json_encode([
    "default_outbound_did" => config("integrations.communications.default_outbound_did"),
    "free_count" => count($free),
    "busy_count" => count($busy),
    "free" => $free,
    "busy" => $busy,
    "all_pool" => $pool,
], JSON_PRETTY_PRINT);
'''

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
out = sudo_run(ssh, f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php", check=False)
ssh.close()
print(out)
