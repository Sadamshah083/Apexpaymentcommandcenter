#!/usr/bin/env python3
"""Check Jacob role + try create johnwilliam the same way as popup."""
from __future__ import annotations

import base64
import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;
use App\Support\SalesOps;
use Illuminate\Support\Facades\DB;

$ws = Workspace::query()->where("name","ApexPayments")->first();
$jacob = User::query()->whereRaw("LOWER(email)=?", ["jacob@apexonepayments.com"])->first();
$pivot = $jacob ? DB::table("workspace_user")->where("workspace_id",$ws->id)->where("user_id",$jacob->id)->first() : null;
echo "JACOB=".json_encode(["id"=>$jacob?->id,"name"=>$jacob?->name,"role"=>$pivot?->role,"status"=>$pivot?->status])."\n";

$tls = $ws->users()->wherePivot("status","active")->wherePivotIn("role",["appointment_setter_team_lead","closers_team_lead"])->get(["users.id","users.name"]);
echo "TEAM_LEADS=".json_encode($tls->map(fn($u)=>["id"=>$u->id,"name"=>$u->name,"role"=>$u->pivot->role]))."\n";

$admin = User::query()->whereRaw("LOWER(email)=?", ["admin@apexonepayments.com"])->first()
    ?? User::query()->orderBy("id")->first();

$email = "johnwilliam@apexonepayments.com";
$existing = User::query()->whereRaw("LOWER(email)=?", [$email])->first();
if ($existing) {
    echo "ALREADY_EXISTS id={$existing->id}\n";
    exit(0);
}

$svc = app(WorkspaceMemberService::class);
try {
    // Prefer a real setter TL; fall back to Jacob id even if role is wrong to see error
    $tlId = optional($tls->first(fn($u)=>($u->pivot->role??'')==='appointment_setter_team_lead'))->id
        ?? optional($tls->first())->id
        ?? $jacob?->id;

    echo "USING_TL={$tlId}\n";
    $user = $svc->createAgent(
        $ws,
        $admin,
        "John William",
        "123456",
        "appointment_setter",
        null,
        $tlId ? (int)$tlId : null,
        null,
        $email,
    );
    echo "CREATED=".json_encode($user->only(["id","name","email"]))."\n";
    $p = DB::table("workspace_user")->where("workspace_id",$ws->id)->where("user_id",$user->id)->first();
    echo "PIVOT=".json_encode($p)."\n";

    // provision phone like popup
    $agent = app(\App\Services\Communications\CommunicationsAgentService::class);
    $lines = $agent->availablePhoneLines($ws);
    $ext = "1022";
    $did = "12107595102";
    foreach ($lines as $line) {
        $e = preg_replace('/\D/','', (string)($line['extension'] ?? $line['suggested_extension'] ?? ''));
        $d = preg_replace('/\D/','', (string)($line['did'] ?? ''));
        if ($e !== '' && $d !== '' && (int)$e >= 1021) { $ext=$e; $did=$d; break; }
    }
    $prov = $agent->provision($ws, $user, [
        "extension_num" => $ext,
        "sip_password" => $agent->ensureSipPassword("123456"),
        "caller_id_name" => "John William",
        "caller_id_num" => $did,
        "create_morpheus_user" => true,
    ]);
    echo "PROVISION=".json_encode($prov)."\n";
    $p2 = DB::table("workspace_user")->where("workspace_id",$ws->id)->where("user_id",$user->id)->first();
    echo "FINAL_PIVOT=".json_encode($p2)."\n";
} catch (Throwable $e) {
    echo "CREATE_ERR=".$e->getMessage()."\n";
    if (method_exists($e, "errors")) {
        echo "ERRORS=".json_encode($e->errors())."\n";
    }
    echo $e->getFile().":".$e->getLine()."\n";
}

echo "SETTER_TLS_EXPECTED_ROLE=appointment_setter_team_lead\n";
echo "IS_JACOB_TL=".json_encode(SalesOps::isAppointmentSetterTeamLead($pivot?->role ?? ''))."\n";
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    enc = base64.b64encode(PHP.encode()).decode()
    inner = f"cd {REMOTE} && echo {enc} | base64 -d | sudo -u www-data php"
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=180)
    print((o.read() + e.read()).decode(errors="replace")[-12000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
