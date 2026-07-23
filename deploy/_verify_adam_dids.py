import base64, shlex, paramiko
HOST, USER, PW = "203.215.161.236", "ateg", "balitech1"
PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\Workspace;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Integrations\ZoomApiService;

$ws = Workspace::where("name","ApexPayments")->first();
$user = User::whereRaw("LOWER(email)=?",["adam.stone@apexonepayments.com"])->first();
$api = app(ZoomApiService::class);

// Find morpheus user by email if possible
$muid = null;
try {
  $users = $api->listUsers(["limit"=>500])["users"] ?? $api->listUsers(["limit"=>500]) ?? [];
  if (isset($users["users"])) $users = $users["users"];
  foreach ((array)$users as $u) {
    if (!is_array($u)) continue;
    if (strcasecmp((string)($u["email"]??""), $user->email)===0 || strcasecmp((string)($u["username"]??""),"adam.stone")===0) {
      $muid = $u["id"] ?? null; break;
    }
  }
} catch (Throwable $e) { echo "listUsers: ".$e->getMessage()."\n"; }

if ($muid) {
  $ws->users()->updateExistingPivot($user->id, ["morpheus_user_id"=>$muid]);
  $api->updateExtension("ae8319d4-f092-4ca1-a714-f21620ba5523", ["user_id"=>$muid]);
}

$lines = app(CommunicationsAgentService::class)->availablePhoneLines($ws);
$pivot = $ws->users()->where("user_id",$user->id)->first()->pivot;
echo json_encode([
  "morpheus_user_id" => $pivot->morpheus_user_id,
  "ext" => $pivot->morpheus_extension_num,
  "team_lead_user_id" => $pivot->team_lead_user_id,
  "available_did_count" => count($lines),
  "has_new_dids" => (bool) array_filter($lines, fn($l)=>str_starts_with($l["did"],"12107595")||str_starts_with($l["did"],"12394231")),
  "dids" => array_column($lines, "did"),
], JSON_PRETTY_PRINT), PHP_EOL;
'''
ssh=paramiko.SSHClient(); ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST,username=USER,password=PW,timeout=40)
enc=base64.b64encode(PHP.encode()).decode()
inner=f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php"
cmd=f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
_,o,e=ssh.exec_command(cmd,timeout=90)
print((o.read()+e.read()).decode(errors='replace'))
ssh.close()
