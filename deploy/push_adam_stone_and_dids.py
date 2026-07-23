#!/usr/bin/env python3
"""Create Adam Stone under Jacob Khan with DID +1-313-385-1218 on NEW server; deploy DID pool UI."""
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
    "app/Http/Controllers/WorkflowController.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/js/workspace-admin.js",
]

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Workspace\WorkspaceMemberService;
use App\Support\SalesOps;
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
    // find setter TL jacob in workspace
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

$email = "adam.stone@apexonepayments.com";
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
        "Adam Stone",
        "123456",
        "appointment_setter",
        null,
        (int) $jacob->id,
        null,
        $email,
    );
    $created = true;
} else {
    // Ensure membership + team lead
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
    if (! Hash::check("123456", $user->password)) {
        $user->forceFill(["password" => Hash::make("123456"), "name" => "Adam Stone"])->save();
    } else {
        $user->forceFill(["name" => "Adam Stone"])->save();
    }
}

$ext = "1001";
$did = "13133851218";
$agentService = app(CommunicationsAgentService::class);
$pivot = $workspace->users()->where("user_id", $user->id)->first()?->pivot;

$result = ["ok" => false, "error" => "skipped"];
if ($pivot && blank($pivot->morpheus_extension_id)) {
    $result = $agentService->provision($workspace, $user, [
        "extension_num" => $ext,
        "sip_password" => "123456",
        "caller_id_name" => "Adam Stone",
        "caller_id_num" => $did,
        "create_morpheus_user" => true,
    ]);
} elseif ($pivot && filled($pivot->morpheus_extension_id)) {
    // Update DID on existing line
    try {
        $api = app(App\Services\Integrations\ZoomApiService::class);
        $patch = $api->updateExtension((string) $pivot->morpheus_extension_id, [
            "caller_id_num" => $did,
            "outbound_cid_num" => $did,
            "caller_id_name" => "Adam Stone",
            "outbound_cid_name" => "Adam Stone",
            "is_dialer_agent" => true,
            "override_campaign_cid" => true,
            "status" => "active",
        ]);
        $result = ["ok" => !isset($patch["error"]) || isset($patch["id"]), "error" => $patch["error"] ?? null, "updated" => true];
        app(App\Services\Communications\MorpheusHubService::class)->bustCache();
    } catch (Throwable $e) {
        $result = ["ok" => false, "error" => $e->getMessage()];
    }
}

$lines = $agentService->availablePhoneLines($workspace);
$pivot = $workspace->users()->where("user_id", $user->id)->first()?->pivot;

echo json_encode([
    "created" => $created,
    "user_id" => $user->id,
    "name" => $user->name,
    "email" => $user->email,
    "team_lead" => $jacob->name . " <" . $jacob->email . ">",
    "team_lead_id" => $jacob->id,
    "role" => $pivot->role ?? null,
    "extension" => $pivot->morpheus_extension_num ?? null,
    "provision" => $result,
    "available_dids" => count($lines),
    "sample_dids" => array_slice(array_column($lines, "did"), 0, 15),
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

    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)

    # Direct put critical files
    sftp = ssh.open_sftp()
    for rel in FILES:
        sftp.put(str(ROOT / rel), f"{REMOTE}/{rel}")
    sftp.close()

    enc = base64.b64encode(PHP.encode()).decode()
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {' '.join(REMOTE + '/' + f for f in FILES)}
# rebuild JS so workspace-admin change is live
if [ -f package.json ]; then
  sudo -u www-data npm run build > /tmp/vite-adam.log 2>&1 || true
  tail -n 5 /tmp/vite-adam.log || true
  chown -R www-data:www-data public/build || true
fi
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
php -l app/Services/Communications/CommunicationsAgentService.php
php -l config/morpheus_billing_dids.php
grep -c "12107595101" config/morpheus_billing_dids.php
grep -n "Type the Morpheus extension" resources/views/workflows/partials/add-member-modal.blade.php | head -2
echo {enc} | base64 -d | sudo -u www-data php
echo DONE
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=300)
    print((o.read() + e.read()).decode(errors="replace")[-8000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
