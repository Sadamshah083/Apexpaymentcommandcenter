#!/usr/bin/env python3
"""Probe how leads are assigned vs visible for Ryan/Tom and other agents."""
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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkflowLead;
use App\Services\Communications\CommunicationsAccessService;
use Illuminate\Support\Facades\DB;

$ws = Workspace::where('name','ApexPayments')->firstOrFail();
$access = app(CommunicationsAccessService::class);

echo "=== sample assigned leads ===\n";
$rows = WorkflowLead::query()
  ->join('workflows','workflows.id','=','workflow_leads.workflow_id')
  ->where('workflows.workspace_id', $ws->id)
  ->whereNotNull('workflow_leads.assigned_user_id')
  ->whereNull('workflow_leads.last_contacted_at')
  ->orderByDesc('workflow_leads.id')
  ->limit(15)
  ->get(['workflow_leads.id','workflow_leads.business_name','workflow_leads.assigned_user_id','workflow_leads.assigned_setter_id','workflow_leads.assigned_closer_id','workflow_leads.pipeline_phase']);

foreach ($rows as $r) {
  echo "lead={$r->id} owner={$r->assigned_user_id} setter={$r->assigned_setter_id} closer={$r->assigned_closer_id} phase={$r->pipeline_phase} {$r->business_name}\n";
}

echo "\n=== agent tiers + dialer counts ===\n";
$agents = User::query()
  ->whereIn(DB::raw('LOWER(email)'), [
    'ryan@apexonepayments.com',
    'tomhanderson@apexonepayments.com',
    'nina@apexonepayments.com',
    'abdul.qadir@apexonepayments.com',
    'elijahmorgan@apexonepayments.com',
  ])->get();

foreach ($agents as $u) {
  $role = $u->getWorkspaceRole($ws->id);
  $tierPortal = $access->tierFor($u, 'portal.');
  $tierAdmin = $access->tierFor($u, 'admin.');
  $mine = WorkflowLead::query()
    ->join('workflows','workflows.id','=','workflow_leads.workflow_id')
    ->where('workflows.workspace_id', $ws->id)
    ->where('workflow_leads.assigned_user_id', $u->id)
    ->whereNull('workflow_leads.last_contacted_at')
    ->count();
  $viaSetter = WorkflowLead::query()
    ->join('workflows','workflows.id','=','workflow_leads.workflow_id')
    ->where('workflows.workspace_id', $ws->id)
    ->where('workflow_leads.assigned_setter_id', $u->id)
    ->where(function($q) use ($u) {
      $q->whereNull('workflow_leads.assigned_user_id')
        ->orWhere('workflow_leads.assigned_user_id', '!=', $u->id);
    })
    ->whereNull('workflow_leads.last_contacted_at')
    ->count();
  echo "{$u->name} role={$role} tier_portal={$tierPortal} tier_admin={$tierAdmin} mine_owner={$mine} via_setter_not_owner={$viaSetter}\n";
}

echo "\n=== overlap: same undialed lead visible to two owners via OR setter ===\n";
$overlap = DB::select("
SELECT l.id, l.assigned_user_id, l.assigned_setter_id, l.assigned_closer_id
FROM workflow_leads l
JOIN workflows w ON w.id = l.workflow_id
WHERE w.workspace_id = ?
  AND l.last_contacted_at IS NULL
  AND l.assigned_user_id IS NOT NULL
  AND l.assigned_setter_id IS NOT NULL
  AND l.assigned_user_id <> l.assigned_setter_id
LIMIT 20
", [$ws->id]);
foreach ($overlap as $o) {
  echo "lead={$o->id} owner={$o->assigned_user_id} setter={$o->assigned_setter_id} closer={$o->assigned_closer_id}\n";
}
echo 'overlap_count='.count($overlap)."\n";
"""

(ROOT / "deploy/_probe_lead_visibility.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_probe_lead_visibility.php", "scripts/_probe_lead_visibility.php")])
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_probe_lead_visibility.php", check=False)
    sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
    sys.stdout.buffer.write(b"\n")
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_probe_lead_visibility.php", check=False)
finally:
    ssh.close()
    p = ROOT / "deploy/_probe_lead_visibility.php"
    if p.exists():
        p.unlink()
