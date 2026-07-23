import base64, shlex
import paramiko
HOST, USER, PW = "203.215.161.236", "ateg", "balitech1"
PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Str;

$workspace = Workspace::query()->where("name", "ApexPayments")->first();
$user = User::query()->whereRaw("LOWER(email)=?", ["adam.stone@apexonepayments.com"])->first();
$api = app(ZoomApiService::class);
$hub = app(MorpheusHubService::class);

$extNum = "1001";
$did = "13133851218";
$sipPass = "AdamStone123!";

$ext = null;
foreach (($api->listExtensions(["limit" => 500])["extensions"] ?? []) as $row) {
    if ((string) ($row["extension_num"] ?? "") === $extNum) {
        $ext = $row;
        break;
    }
}
if (!$ext || empty($ext["id"])) {
    fwrite(STDERR, "ext 1001 missing\n");
    exit(1);
}

// Ensure Morpheus user exists / linked
$pivot = $workspace->users()->where("user_id", $user->id)->first()->pivot;
$morpheusUserId = $pivot->morpheus_user_id;
if (! $morpheusUserId) {
    $created = $api->createUser([
        "username" => "adam.stone",
        "password" => $sipPass,
        "email" => $user->email,
        "first_name" => "Adam",
        "last_name" => "Stone",
        "role" => "user",
        "status" => "active",
        "user_level" => 5,
    ]);
    if (isset($created["id"])) {
        $morpheusUserId = $created["id"];
    } elseif (isset($created["error"])) {
        // maybe exists — try list users by email if available
        echo "createUser note: ".json_encode($created)."\n";
    }
}

$patch = $api->updateExtension((string) $ext["id"], array_filter([
    "password" => $sipPass,
    "caller_id_num" => $did,
    "outbound_cid_num" => $did,
    "caller_id_name" => "Adam Stone",
    "outbound_cid_name" => "Adam Stone",
    "user_id" => $morpheusUserId,
    "is_dialer_agent" => true,
    "override_campaign_cid" => true,
    "status" => "active",
], fn ($v) => $v !== null && $v !== ""));

$workspace->users()->updateExistingPivot($user->id, [
    "morpheus_user_id" => $morpheusUserId,
    "morpheus_extension_id" => (string) $ext["id"],
    "morpheus_extension_num" => $extNum,
    "team_lead_user_id" => 18,
    "role" => "appointment_setter",
    "status" => "active",
]);

$hub->bustCache();

$live = null;
foreach (($api->listExtensions(["limit" => 500])["extensions"] ?? []) as $row) {
    if ((string) ($row["extension_num"] ?? "") === $extNum) {
        $live = $row;
        break;
    }
}
$pivot = $workspace->users()->where("user_id", $user->id)->first()->pivot;
$jacob = User::find(18);

echo json_encode([
    "ok" => !isset($patch["error"]) || isset($patch["id"]),
    "patch_error" => $patch["error"] ?? null,
    "adam" => [
        "id" => $user->id,
        "name" => $user->name,
        "email" => $user->email,
        "password_login" => "123456",
        "team_lead" => $jacob?->name,
        "ext" => $pivot->morpheus_extension_num,
        "ext_id" => $pivot->morpheus_extension_id,
    ],
    "live" => [
        "extension_num" => $live["extension_num"] ?? null,
        "caller_id_num" => $live["caller_id_num"] ?? null,
        "outbound_cid_num" => $live["outbound_cid_num"] ?? null,
        "caller_id_name" => $live["caller_id_name"] ?? null,
    ],
], JSON_PRETTY_PRINT), PHP_EOL;
'''
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=40)
enc = base64.b64encode(PHP.encode()).decode()
inner = f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php"
cmd = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
_, o, e = ssh.exec_command(cmd, timeout=120)
print((o.read()+e.read()).decode(errors='replace'))
ssh.close()
