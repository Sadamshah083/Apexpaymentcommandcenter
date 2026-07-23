import base64, shlex, io, tarfile
from pathlib import Path
import paramiko
ROOT = Path(r"C:\Users\dev\Desktop\ApexonecommandCenter")
HOST, USER, PW = "203.215.161.236", "ateg", "balitech1"
REMOTE = "/var/www/apexone"

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;

$workspace = Workspace::query()->where("name", "ApexPayments")->first();
$user = User::query()->whereRaw("LOWER(email)=?", ["adam.stone@apexonepayments.com"])->first();
if (!$workspace || !$user) { fwrite(STDERR, "missing\n"); exit(1); }

$agent = app(CommunicationsAgentService::class);
$pivot = $workspace->users()->where("user_id", $user->id)->first()?->pivot;
$ext = "1001";
$did = "13133851218";
$sipPass = "AdamStone123!";

// Clear stale pivot line if provision failed mid-way
if ($pivot && blank($pivot->morpheus_extension_id)) {
    $result = $agent->provision($workspace, $user, [
        "extension_num" => $ext,
        "sip_password" => $sipPass,
        "caller_id_name" => "Adam Stone",
        "caller_id_num" => $did,
        "create_morpheus_user" => true,
    ]);
    echo json_encode(["mode"=>"provision","result"=>$result], JSON_PRETTY_PRINT), "\n";
} else {
    $api = app(ZoomApiService::class);
    $id = (string) ($pivot->morpheus_extension_id ?? "");
    if ($id === "") {
        // find ext 1001
        foreach (($api->listExtensions(["limit"=>500])["extensions"] ?? []) as $row) {
            if ((string)($row["extension_num"] ?? "") === $ext) { $id = (string)$row["id"]; break; }
        }
    }
    $patch = $api->updateExtension($id, [
        "password" => $sipPass,
        "caller_id_num" => $did,
        "outbound_cid_num" => $did,
        "caller_id_name" => "Adam Stone",
        "outbound_cid_name" => "Adam Stone",
        "is_dialer_agent" => true,
        "override_campaign_cid" => true,
        "status" => "active",
    ]);
    $workspace->users()->updateExistingPivot($user->id, [
        "morpheus_extension_id" => $id,
        "morpheus_extension_num" => $ext,
    ]);
    app(MorpheusHubService::class)->bustCache();
    echo json_encode(["mode"=>"update","ext_id"=>$id,"patch_ok"=>!isset($patch["error"])||isset($patch["id"]),"error"=>$patch["error"]??null], JSON_PRETTY_PRINT), "\n";
}

$pivot = $workspace->users()->where("user_id", $user->id)->first()?->pivot;
$api = app(ZoomApiService::class);
$live = null;
foreach (($api->listExtensions(["limit"=>500])["extensions"] ?? []) as $row) {
    if ((string)($row["extension_num"] ?? "") === "1001") {
        $live = [
            "extension" => $row["extension_num"] ?? null,
            "caller_id_num" => $row["caller_id_num"] ?? null,
            "outbound_cid_num" => $row["outbound_cid_num"] ?? null,
            "caller_id_name" => $row["caller_id_name"] ?? null,
        ];
        break;
    }
}
echo json_encode([
    "user" => $user->email,
    "team_lead_user_id" => $pivot->team_lead_user_id ?? null,
    "pivot_ext" => $pivot->morpheus_extension_num ?? null,
    "live_1001" => $live,
    "available_dids" => count($agent->availablePhoneLines($workspace)),
], JSON_PRETTY_PRINT), PHP_EOL;
'''

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=40)

# upload build
buf = io.BytesIO()
with tarfile.open(fileobj=buf, mode="w:gz") as tar:
    tar.add(ROOT / "public" / "build", arcname="public/build")
buf.seek(0)
sftp = ssh.open_sftp()
sftp.putfo(buf, "/tmp/apexone-build-adam.tgz")
sftp.close()

enc = base64.b64encode(PHP.encode()).decode()
inner = f"""
set -e
cd {REMOTE}
tar -xzf /tmp/apexone-build-adam.tgz -C {REMOTE}
rm -f /tmp/apexone-build-adam.tgz
chown -R www-data:www-data public/build
echo {enc} | base64 -d | sudo -u www-data php
"""
cmd = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
_, o, e = ssh.exec_command(cmd, timeout=180)
print((o.read()+e.read()).decode(errors='replace'))
ssh.close()
