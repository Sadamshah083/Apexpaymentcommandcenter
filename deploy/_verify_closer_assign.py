#!/usr/bin/env python3
"""Verify B2B Closer TL + members are assignable for enriched imports."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\WorkflowAssignmentRoles;
use App\Services\Pipeline\SetterDistributionService;
use App\Models\User;

$ws = Workspace::find(2);
if (!$ws) { echo "NO_WORKSPACE\n"; exit(1); }

$tls = WorkflowAssignmentRoles::assignableTeamLeadsFor($ws);
echo "workspace={$ws->id} {$ws->name}\n";
echo "team_leads={$tls->count()}\n";
foreach ($tls as $tl) {
    $cid = (int) ($tl->pivot->campaign_id ?? 0);
    $agents = WorkflowAssignmentRoles::agentsForTeamLead($ws, $tl);
    echo "TL {$tl->id} {$tl->name} role={$tl->pivot->role} campaign={$cid} agents={$agents->count()}\n";
    foreach ($agents->take(8) as $a) {
        echo "  member {$a->id} {$a->name} role={$a->pivot->role}\n";
    }
}

$map = WorkflowAssignmentRoles::assignableTeamMemberMap($ws);
echo "map_keys=" . implode(",", array_map('strval', array_keys($map))) . "\n";
foreach ($map as $tlId => $members) {
    echo "map[{$tlId}]=" . count($members) . "\n";
}

$wf = Workflow::query()
    ->where('workspace_id', $ws->id)
    ->where(function ($q) {
        $q->where('original_filename', 'like', '%Dallas%')
          ->orWhere('name', 'like', '%Dallas%')
          ->orWhere('original_filename', 'like', '%Auto_repair%');
    })
    ->orderByDesc('id')
    ->first();

if ($wf) {
    $ready = WorkflowLead::query()->where('workflow_id', $wf->id)->readyToAssign()->count();
    echo "workflow={$wf->id} file={$wf->original_filename} ready={$ready}\n";

    $damon = $ws->users()->where('users.id', 17)->first();
    if ($damon && $ready > 0) {
        $svc = app(SetterDistributionService::class);
        $admin = User::find(1) ?? $ws->users()->wherePivot('role', 'admin')->first();
        $assigned = $svc->assignWorkflowLeadsToTeamLead($ws, $wf, $damon, min(2, $ready), $admin, []);
        $readyAfter = WorkflowLead::query()->where('workflow_id', $wf->id)->readyToAssign()->count();
        $sample = WorkflowLead::query()
            ->where('workflow_id', $wf->id)
            ->where('pipeline_phase', 'with_closer')
            ->orderByDesc('id')
            ->limit(2)
            ->get(['id','assigned_user_id','assigned_closer_id','campaign_id','pipeline_phase','closer_status']);
        echo "test_assign={$assigned} ready_after={$readyAfter}\n";
        foreach ($sample as $row) {
            echo "lead {$row->id} user={$row->assigned_user_id} closer={$row->assigned_closer_id} campaign={$row->campaign_id} phase={$row->pipeline_phase} status={$row->closer_status}\n";
        }
    }
} else {
    echo "workflow=none\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_closer_assign.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_closer_assign.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
