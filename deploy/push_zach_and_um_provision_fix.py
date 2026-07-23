#!/usr/bin/env python3
"""Deploy User Management phone provision fix + create Zach Willson under Jacob Khan on NEW."""
from __future__ import annotations

import base64
import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
REMOTE = "/var/www/apexone"
FILES = [
    "config/morpheus_billing_dids.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
]

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Workspace\WorkspaceMemberService;
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

$agentService = app(CommunicationsAgentService::class);

// --- Smoke test: short login password must pad to >=8 for SIP ---
$padded = $agentService->ensureSipPassword("123456");
if (strlen($padded) < 8) {
    fwrite(STDERR, "ensureSipPassword failed: {$padded}\n");
    exit(1);
}

$email = "zachwillson@apexonepayments.com";
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
        "Zach Willson",
        "123456",
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
        "password" => Hash::make("123456"),
        "password_hint" => "123456",
        "name" => "Zach Willson",
    ])->save();
}

// Prefer next free DID from pool (1021+), fallback hard-coded.
$ext = "1021";
$did = "12107595101";
$lines = $agentService->availablePhoneLines($workspace);
foreach ($lines as $line) {
    $candidateExt = preg_replace("/\D/", "", (string) ($line["extension"] ?? $line["suggested_extension"] ?? ""));
    $candidateDid = preg_replace("/\D/", "", (string) ($line["did"] ?? ""));
    if ($candidateExt !== "" && $candidateDid !== "" && (int) $candidateExt >= 1021) {
        $ext = $candidateExt;
        $did = $candidateDid;
        break;
    }
}

$pivot = $workspace->users()->where("user_id", $user->id)->first()?->pivot;
$result = ["ok" => false, "error" => "skipped"];

if ($pivot && blank($pivot->morpheus_extension_id)) {
    $result = $agentService->provision($workspace, $user, [
        "extension_num" => $ext,
        "sip_password" => $agentService->ensureSipPassword("123456"),
        "caller_id_name" => "Zach Willson",
        "caller_id_num" => $did,
        "create_morpheus_user" => true,
    ]);
} elseif ($pivot && filled($pivot->morpheus_extension_id)) {
    try {
        $api = app(App\Services\Integrations\ZoomApiService::class);
        $patch = $api->updateExtension((string) $pivot->morpheus_extension_id, [
            "caller_id_num" => $did,
            "outbound_cid_num" => $did,
            "caller_id_name" => "Zach Willson",
            "outbound_cid_name" => "Zach Willson",
            "is_dialer_agent" => true,
            "override_campaign_cid" => true,
            "status" => "active",
        ]);
        $workspace->users()->updateExistingPivot($user->id, [
            "morpheus_extension_num" => $pivot->morpheus_extension_num ?: $ext,
            "team_lead_user_id" => $jacob->id,
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

$pivot = $workspace->users()->where("user_id", $user->id)->first()?->pivot;
$lines = $agentService->availablePhoneLines($workspace);

echo json_encode([
    "sip_pad_test" => $padded,
    "created" => $created,
    "user_id" => $user->id,
    "name" => $user->name,
    "email" => $user->email,
    "login_password" => "123456",
    "team_lead" => $jacob->name . " <" . $jacob->email . ">",
    "team_lead_id" => $jacob->id,
    "role" => $pivot->role ?? null,
    "team_lead_user_id" => $pivot->team_lead_user_id ?? null,
    "extension" => $pivot->morpheus_extension_num ?? null,
    "morpheus_extension_id" => $pivot->morpheus_extension_id ?? null,
    "assigned_did" => $did,
    "provision" => $result,
    "available_dids" => count($lines),
    "next_dids" => array_slice(array_map(static fn ($l) => [
        "ext" => $l["extension"] ?? null,
        "did" => $l["did"] ?? null,
    ], $lines), 0, 8),
], JSON_PRETTY_PRINT), PHP_EOL;
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    pairs = [(ROOT / rel, rel) for rel in FILES]
    print(f"Uploading {len(pairs)} files to NEW...")
    upload_files(ssh, pairs, app_root=REMOTE)

    enc = base64.b64encode(PHP.encode()).decode()
    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
php -l app/Services/Communications/CommunicationsAgentService.php
php -l app/Http/Controllers/WorkspaceMemberController.php
grep -n "ensureSipPassword" app/Services/Communications/CommunicationsAgentService.php | head -5
grep -n "ensureSipPassword" app/Http/Controllers/WorkspaceMemberController.php | head -5
echo {enc} | base64 -d | sudo -u www-data php
echo DONE
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=300)
    print((o.read() + e.read()).decode(errors="replace")[-12000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
