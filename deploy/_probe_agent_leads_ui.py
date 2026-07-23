#!/usr/bin/env python3
"""Probe why agent assigned leads show empty on dialer."""

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
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$pivotCols = Schema::getColumnListing("workspace_user");
$uid = null;
foreach (["extension_number", "morpheus_extension", "phone_extension", "extension"] as $col) {
    if (in_array($col, $pivotCols, true)) {
        $uid = DB::table("workspace_user")->where($col, "1002")->value("user_id");
        if ($uid) break;
    }
}
if (!$uid) {
    $uid = WorkflowLead::query()->whereNotNull("assigned_user_id")->orderByDesc("id")->value("assigned_user_id");
}
$user = $uid ? User::find($uid) : null;
if (!$user) {
    echo json_encode(["error" => "no user", "pivot_cols" => $pivotCols]);
    exit;
}

$ws = app(WorkspaceContextService::class)->resolveActiveWorkspace($user);
$svc = app(DialerImportedLeadsService::class);

$files = $svc->fileOptions($ws, (int) $user->id);
$filters = [
    "assigned_user_id" => (int) $user->id,
    "pool" => "assigned",
    "workflow_ids" => [],
];
$page = $svc->paginate($ws, $filters, 0, 10);

// Illinois file match
$illinois = Workflow::query()
    ->where("workspace_id", $ws->id)
    ->where(function ($q) {
        $q->where("original_filename", "like", "%Illinois Auto Repair%")
          ->orWhere("name", "like", "%Illinois Auto Repair%");
    })
    ->first(["id", "name", "original_filename", "total_leads", "agent_restricted"]);

$illinoisAssigned = 0;
$illinoisUndialed = 0;
$illinoisPage = null;
if ($illinois) {
    $illinoisAssigned = WorkflowLead::query()
        ->where("workflow_id", $illinois->id)
        ->where("assigned_user_id", $user->id)
        ->count();
    $illinoisUndialed = WorkflowLead::query()
        ->where("workflow_id", $illinois->id)
        ->where("assigned_user_id", $user->id)
        ->whereNull("last_contacted_at")
        ->where(function ($q) { $q->whereNull("contact_attempts")->orWhere("contact_attempts", "<=", 0); })
        ->where(function ($q) { $q->whereNull("last_disposition")->orWhere("last_disposition", ""); })
        ->count();
    $illinoisPage = $svc->paginate($ws, [
        "assigned_user_id" => (int) $user->id,
        "pool" => "assigned",
        "workflow_ids" => [$illinois->id],
    ], 0, 10);
}

$assignedTotal = WorkflowLead::query()
    ->join("workflows", "workflows.id", "=", "workflow_leads.workflow_id")
    ->where("workflows.workspace_id", $ws->id)
    ->where("workflow_leads.assigned_user_id", $user->id)
    ->count();

$undialed = WorkflowLead::query()
    ->join("workflows", "workflows.id", "=", "workflow_leads.workflow_id")
    ->where("workflows.workspace_id", $ws->id)
    ->where("workflow_leads.assigned_user_id", $user->id)
    ->whereNull("workflow_leads.last_contacted_at")
    ->where(function ($q) { $q->whereNull("contact_attempts")->orWhere("contact_attempts", "<=", 0); })
    ->where(function ($q) { $q->whereNull("last_disposition")->orWhere("last_disposition", ""); })
    ->count();

$sample = WorkflowLead::query()
    ->join("workflows", "workflows.id", "=", "workflow_leads.workflow_id")
    ->where("workflows.workspace_id", $ws->id)
    ->where("workflow_leads.assigned_user_id", $user->id)
    ->orderByDesc("workflow_leads.id")
    ->limit(8)
    ->get([
        "workflow_leads.id",
        "workflow_leads.workflow_id",
        "workflow_leads.normalized_phone",
        "workflow_leads.input_phone",
        "workflow_leads.direct_phone",
        "workflow_leads.last_contacted_at",
        "workflow_leads.contact_attempts",
        "workflow_leads.last_disposition",
        "workflow_leads.status",
        "workflows.agent_restricted",
        "workflows.original_filename",
    ]);

$role = DB::table("workspace_user")->where("user_id", $user->id)->where("workspace_id", $ws->id)->value("role");

echo json_encode([
    "user_id" => $user->id,
    "user_name" => $user->name,
    "email" => $user->email,
    "role" => $role,
    "workspace_id" => $ws->id,
    "files_count" => count($files),
    "files" => $files,
    "page_total" => $page["total"] ?? null,
    "page_leads" => count($page["leads"] ?? []),
    "page_first" => ($page["leads"][0] ?? null),
    "assigned_total" => $assignedTotal,
    "undialed" => $undialed,
    "illinois" => $illinois,
    "illinois_assigned" => $illinoisAssigned,
    "illinois_undialed" => $illinoisUndialed,
    "illinois_page_total" => $illinoisPage["total"] ?? null,
    "illinois_page_leads" => isset($illinoisPage) ? count($illinoisPage["leads"] ?? []) : null,
    "sample" => $sample,
], JSON_PRETTY_PRINT);
'''


def main() -> None:
    ssh = connect()
    try:
        remote = "/tmp/_probe_agent_leads.php"
        sftp = ssh.open_sftp()
        with sftp.file(remote, "w") as f:
            f.write(PROBE)
        sftp.close()
        out = sudo_run(
            ssh,
            f"cp {remote} {REMOTE_APP}/_probe_agent_leads.php && cd {REMOTE_APP} && php _probe_agent_leads.php; rm -f {REMOTE_APP}/_probe_agent_leads.php {remote}",
            check=False,
        )
        print(out)
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
