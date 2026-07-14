#!/usr/bin/env python3
from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ws = App\Models\Workspace::where('name', 'ApexPayments')->first();
$agents = $ws->users()
    ->wherePivotIn('role', ['appointment_setter', 'closer', 'appointment_setter_team_lead'])
    ->wherePivot('status', 'active')
    ->get(['users.id', 'users.name']);

foreach ($agents as $a) {
    $role = $a->pivot->role;
    $as = App\Models\WorkflowLead::query()->where('assigned_user_id', $a->id)->count();
    $dialable = App\Models\WorkflowLead::query()
        ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
        ->where('workflows.workspace_id', $ws->id)
        ->where('workflow_leads.assigned_user_id', $a->id)
        ->whereNotIn('workflow_leads.status', ['failed', 'rejected'])
        ->whereNull('workflow_leads.last_contacted_at')
        ->where(function ($q) {
            $q->whereNull('contact_attempts')->orWhere('contact_attempts', '<=', 0);
        })
        ->where(function ($q) {
            $q->whereNotNull('normalized_phone')
                ->orWhereNotNull('direct_phone')
                ->orWhereNotNull('input_phone');
        })
        ->count();
    $withPhoneFail = App\Models\WorkflowLead::query()
        ->where('assigned_user_id', $a->id)
        ->whereNull('normalized_phone')
        ->whereNull('direct_phone')
        ->whereNull('input_phone')
        ->count();
    echo "id={$a->id} name={$a->name} role={$role} assigned={$as} dialable={$dialable} no_phone={$withPhoneFail}\n";
}

$sample = App\Models\WorkflowLead::query()->whereNotNull('assigned_user_id')->orderByDesc('id')->first();
if ($sample) {
    echo json_encode([
        'id' => $sample->id,
        'assigned_user_id' => $sample->assigned_user_id,
        'assigned_setter_id' => $sample->assigned_setter_id,
        'status' => $sample->status,
        'phase' => $sample->pipeline_phase,
        'setter_status' => $sample->setter_status,
        'last_contacted_at' => $sample->last_contacted_at,
        'contact_attempts' => $sample->contact_attempts,
        'campaign_id' => $sample->campaign_id,
        'phones' => [$sample->normalized_phone, $sample->direct_phone, $sample->input_phone],
    ], JSON_UNESCAPED_UNICODE)."\n";
}

$campaigns = App\Models\LeadCampaign::query()->where('workspace_id', $ws->id)->get(['id','name']);
foreach ($campaigns as $c) {
    echo "campaign id={$c->id} name={$c->name}\n";
}
"""


def main() -> int:
    ssh = connect()
    remote = f"{REMOTE_APP}/storage/app/_probe_agent_leads.php"
    b64 = base64.b64encode(PHP.encode()).decode()
    sudo_run(ssh, f"printf %s {shlex.quote(b64)} | base64 -d > {remote} && chown www-data:www-data {remote}")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_probe_agent_leads.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
