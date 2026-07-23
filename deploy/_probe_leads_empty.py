#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as ssh_mod

ssh_mod.HOST = "203.215.161.236"
ssh_mod.USER = "ateg"
ssh_mod.PASSWORD = "balitech1"
ssh_mod.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run

PROBE = r'''
<?php
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Communications\DialerImportedLeadsService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Support\Facades\DB;

$uid = DB::table("workspace_user")->where("morpheus_extension_num", "1002")->value("user_id");
$user = User::find($uid);
$ws = app(WorkspaceContextService::class)->resolveActiveWorkspace($user);
$svc = app(DialerImportedLeadsService::class);

$files = $svc->fileOptions($ws, (int)$user->id);
$page = $svc->paginate($ws, [
    "assigned_user_id" => (int)$user->id,
    "pool" => "assigned",
    "workflow_ids" => [],
], 0, 25);

$phones = collect($page["leads"] ?? [])->pluck("phone")->filter()->count();
$noPhone = collect($page["leads"] ?? [])->filter(fn ($l) => empty($l["phone"]))->count();

echo json_encode([
    "user" => $user->only(["id","name","email"]),
    "files" => $files,
    "total" => $page["total"] ?? null,
    "leads_count" => count($page["leads"] ?? []),
    "with_phone" => $phones,
    "without_phone" => $noPhone,
    "first_three" => array_slice($page["leads"] ?? [], 0, 3),
    "has_more" => $page["has_more"] ?? null,
], JSON_PRETTY_PRINT);
'''

def main() -> None:
    ssh = connect()
    try:
        remote = "/tmp/_probe_leads3.php"
        sftp = ssh.open_sftp()
        with sftp.file(remote, "w") as f:
            f.write(PROBE)
        sftp.close()
        print(sudo_run(ssh, f"cp {remote} {REMOTE_APP}/_p.php && cd {REMOTE_APP} && php _p.php; rm -f {REMOTE_APP}/_p.php {remote}", check=False))
    finally:
        ssh.close()

if __name__ == "__main__":
    main()
