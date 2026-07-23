#!/usr/bin/env python3
"""Create Alma Scott under Jacob Khan on NEW (appointment setter agent)."""
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
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$workspace = Workspace::query()->where("name", "ApexPayments")->first()
    ?? Workspace::query()->orderBy("id")->first();
if (! $workspace) {
    fwrite(STDERR, "No workspace\n");
    exit(1);
}

$jacob = User::query()
    ->whereRaw("LOWER(email) = ?", ["jacob@apexonepayments.com"])
    ->orWhereRaw("LOWER(name) like ?", ["%jacob%khan%"])
    ->first();

if (! $jacob) {
    $jacob = $workspace->users()
        ->where(function ($q) {
            $q->whereRaw("LOWER(users.email) like ?", ["%jacob%"])
              ->orWhereRaw("LOWER(users.name) like ?", ["%jacob%"]);
        })
        ->first();
}

if (! $jacob) {
    fwrite(STDERR, "Jacob Khan not found\n");
    exit(1);
}

$email = "almascott@apexonepayments.com";
$name = "Alma Scott";
$ext = "1020";
$did = "12016485968";
$loginPassword = "123456";

$user = User::query()->whereRaw("LOWER(email) = ?", [strtolower($email)])->first();
$created = false;

if (! $user) {
    $admin = User::query()->whereRaw("LOWER(email) = ?", ["admin@apexonepayments.com"])->first()
        ?? $workspace->admin
        ?? User::query()->orderBy("id")->first();

    $memberService = app(WorkspaceMemberService::class);
    $user = $memberService->createAgent(
        $workspace,
        $admin,
        $name,
        $loginPassword,
        "appointment_setter",
        null,
        (int) $jacob->id,
        null,
        $email,
    );
    $created = true;
} else {
    if (! $workspace->users()->where("user_id", $user->id)->exists()) {
        $workspace->users()->attach($user->id, [
            "role" => "appointment_setter",
            "status" => "active",
            "joined_at" => now(),
        ]);
    }
    $workspace->users()->updateExistingPivot($user->id, [
        "role" => "appointment_setter",
        "status" => "active",
        "team_lead_user_id" => $jacob->id,
    ]);
    $user->forceFill([
        "password" => Hash::make($loginPassword),
        "password_hint" => $loginPassword,
        "name" => $name,
    ])->save();
}

$agentService = app(CommunicationsAgentService::class);
$pivot = $workspace->users()->where("user_id", $user->id)->first()?->pivot;
$result = ["ok" => false, "error" => "skipped"];

if ($pivot && blank($pivot->morpheus_extension_id)) {
    $result = $agentService->provision($workspace, $user, [
        "extension_num" => $ext,
        "sip_password" => $agentService->ensureSipPassword($loginPassword),
        "caller_id_name" => $name,
        "caller_id_num" => $did,
        "create_morpheus_user" => true,
    ]);
} elseif ($pivot && filled($pivot->morpheus_extension_id)) {
    try {
        $api = app(App\Services\Integrations\ZoomApiService::class);
        $patch = $api->updateExtension((string) $pivot->morpheus_extension_id, [
            "caller_id_num" => $did,
            "outbound_cid_num" => $did,
            "caller_id_name" => $name,
            "outbound_cid_name" => $name,
            "is_dialer_agent" => true,
            "override_campaign_cid" => true,
            "status" => "active",
        ]);
        $workspace->users()->updateExistingPivot($user->id, [
            "morpheus_extension_number" => $ext,
            "outbound_caller_id" => $did,
        ]);
        $result = [
            "ok" => ! isset($patch["error"]) || isset($patch["id"]),
            "error" => $patch["error"] ?? null,
            "updated" => true,
        ];
        app(App\Services\Communications\MorpheusHubService::class)->bustCache();
    } catch (Throwable $e) {
        $result = ["ok" => false, "error" => $e->getMessage()];
    }
}

$final = DB::table("workspace_user")->where("workspace_id", $workspace->id)->where("user_id", $user->id)->first();

echo json_encode([
    "created" => $created,
    "user_id" => $user->id,
    "name" => $user->name,
    "email" => $user->email,
    "password" => $loginPassword,
    "team_lead" => ["id" => $jacob->id, "name" => $jacob->name, "email" => $jacob->email],
    "role" => $final->role ?? null,
    "team_lead_user_id" => $final->team_lead_user_id ?? null,
    "extension" => $final->morpheus_extension_number ?? $ext,
    "did" => $final->outbound_caller_id ?? $did,
    "morpheus_extension_id" => $final->morpheus_extension_id ?? null,
    "provision" => $result,
], JSON_PRETTY_PRINT) . PHP_EOL;
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
