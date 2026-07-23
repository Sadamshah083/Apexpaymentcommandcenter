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
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Communications\DialerImportedLeadsService;
use App\Services\Communications\CommunicationsAccessService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$pivotCols = Schema::getColumnListing("workspace_user");
echo "pivot_cols=" . implode(",", $pivotCols) . "\n";

// Find Illinois + Alaska workflows
$illinois = Workflow::query()->where("original_filename", "like", "%Illinois Auto Repair%")->first();
$alaska = Workflow::query()->where("original_filename", "like", "%Alaska Auto%")->first();
echo "illinois=" . json_encode($illinois?->only(["id","original_filename","agent_restricted","total_leads"])) . "\n";
echo "alaska=" . json_encode($alaska?->only(["id","original_filename","agent_restricted","total_leads"])) . "\n";

foreach ([$illinois, $alaska] as $wf) {
    if (!$wf) continue;
    $byUser = WorkflowLead::query()
        ->selectRaw("assigned_user_id, count(*) as c")
        ->where("workflow_id", $wf->id)
        ->whereNotNull("assigned_user_id")
        ->groupBy("assigned_user_id")
        ->orderByDesc("c")
        ->limit(10)
        ->get();
    echo "wf{$wf->id}_assigned=" . $byUser->toJson() . "\n";
}

// Find extension 1002 on pivot
$extRow = null;
foreach (["extension_number","morpheus_extension","outbound_extension","sip_extension"] as $col) {
    if (in_array($col, $pivotCols, true)) {
        $extRow = DB::table("workspace_user")->where($col, "1002")->first();
        if ($extRow) { echo "ext_col=$col\n"; break; }
    }
}
if (!$extRow) {
    // search any string cols
    $rows = DB::table("workspace_user")->limit(200)->get();
    foreach ($rows as $row) {
        foreach ((array)$row as $k=>$v) {
            if ((string)$v === "1002") { $extRow = $row; echo "found_1002_in=$k\n"; break 2; }
        }
    }
}
echo "ext_row=" . json_encode($extRow) . "\n";

$userId = $extRow->user_id ?? null;
if (!$userId && $illinois) {
    $userId = WorkflowLead::query()->where("workflow_id", $illinois->id)->whereNotNull("assigned_user_id")->value("assigned_user_id");
}
$user = $userId ? User::find($userId) : null;
echo "user=" . json_encode($user?->only(["id","name","email"])) . "\n";
if (!$user) exit;

$ws = app(WorkspaceContextService::class)->resolveActiveWorkspace($user);
$role = DB::table("workspace_user")->where("user_id", $user->id)->where("workspace_id", $ws->id)->value("role");
$access = app(CommunicationsAccessService::class);
$meta = $access->viewMeta($user, "portal.");
echo "role=$role meta=" . json_encode($meta) . "\n";

$svc = app(DialerImportedLeadsService::class);
$files = $svc->fileOptions($ws, (int)$user->id);
echo "files=" . json_encode($files) . "\n";

$pageAll = $svc->paginate($ws, ["assigned_user_id"=>(int)$user->id,"pool"=>"assigned","workflow_ids"=>[]], 0, 25);
echo "page_all_total={$pageAll['total']} leads=" . count($pageAll["leads"]) . "\n";

if ($illinois) {
    $pageIl = $svc->paginate($ws, ["assigned_user_id"=>(int)$user->id,"pool"=>"assigned","workflow_ids"=>[(int)$illinois->id]], 0, 25);
    echo "page_illinois_total={$pageIl['total']} leads=" . count($pageIl["leads"]) . "\n";
}

// Who can see Illinois in fileOptions with assigned=0 but total 632? That would be a bug in fileOptions fallback
// Check if any agent has illinois undialed assigned
if ($illinois) {
    $undialedBy = WorkflowLead::query()
        ->selectRaw("assigned_user_id, count(*) as c")
        ->where("workflow_id", $illinois->id)
        ->whereNotNull("assigned_user_id")
        ->whereNull("last_contacted_at")
        ->where(function ($q) { $q->whereNull("contact_attempts")->orWhere("contact_attempts", "<=", 0); })
        ->where(function ($q) { $q->whereNull("last_disposition")->orWhere("last_disposition", ""); })
        ->groupBy("assigned_user_id")
        ->get();
    echo "illinois_undialed_by=" . $undialedBy->toJson() . "\n";
}
'''

def main() -> None:
    ssh = connect()
    try:
        remote = "/tmp/_probe2.php"
        sftp = ssh.open_sftp()
        with sftp.file(remote, "w") as f:
            f.write(PROBE)
        sftp.close()
        print(sudo_run(ssh, f"cp {remote} {REMOTE_APP}/_probe2.php && cd {REMOTE_APP} && php _probe2.php; rm -f {REMOTE_APP}/_probe2.php {remote}", check=False))
    finally:
        ssh.close()

if __name__ == "__main__":
    main()
