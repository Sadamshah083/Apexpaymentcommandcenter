#!/usr/bin/env python3
"""Why assigned leads with phones still miss dialer total."""

from __future__ import annotations

import base64
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WorkflowLead;
use Illuminate\Support\Facades\DB;

$uid = 14;
$base = WorkflowLead::query()->where("assigned_user_id", $uid);
echo "assigned=".$base->count()."\n";

$phoneOk = (clone $base)->where(function ($phone) {
    $phone->where(function ($normalized) {
        $normalized->whereNotNull("normalized_phone")->where("normalized_phone", "!=", "")->whereRaw("normalized_phone REGEXP '[0-9]{10}'");
    })->orWhere(function ($direct) {
        $direct->whereNotNull("direct_phone")->where("direct_phone", "!=", "")->where("direct_phone", "not like", "%Not Publicly%")->whereRaw("direct_phone REGEXP '[0-9]{10}'");
    })->orWhere(function ($input) {
        $input->whereNotNull("input_phone")->where("input_phone", "!=", "")->where("input_phone", "not like", "%Not Publicly%")->whereRaw("input_phone REGEXP '[0-9]{10}'");
    });
});
echo "phone_ok=".$phoneOk->count()."\n";

foreach (["status","pipeline_phase","setter_status","campaign_id"] as $col) {
    $rows = DB::table("workflow_leads")->where("assigned_user_id", $uid)->select($col, DB::raw("count(*) c"))->groupBy($col)->orderByDesc("c")->get();
    echo "\nby {$col}:\n";
    foreach ($rows as $r) echo "  ".var_export($r->$col, true)." => {$r->c}\n";
}

// Compare phone_ok vs statuses dialer excludes
$failed = (clone $phoneOk)->whereIn("status", ["failed","rejected"])->count();
echo "\nphone_ok_but_failed_rejected=$failed\n";

$phoneOkNotFailed = (clone $phoneOk)->whereNotIn("status", ["failed","rejected"]);
echo "phone_ok_not_failed=".$phoneOkNotFailed->count()."\n";

// Simulate full dialer join workspace
$wsId = DB::table("workspaces")->where("name","ApexPayments")->value("id");
$joined = WorkflowLead::query()
    ->join("workflows", "workflows.id", "=", "workflow_leads.workflow_id")
    ->where("workflows.workspace_id", $wsId)
    ->where("workflow_leads.assigned_user_id", $uid)
    ->whereNotIn("workflow_leads.status", ["failed","rejected"])
    ->where(function ($phone) {
        $phone->where(function ($normalized) {
            $normalized->whereNotNull("workflow_leads.normalized_phone")->where("workflow_leads.normalized_phone", "!=", "")->whereRaw("workflow_leads.normalized_phone REGEXP '[0-9]{10}'");
        })->orWhere(function ($direct) {
            $direct->whereNotNull("workflow_leads.direct_phone")->where("workflow_leads.direct_phone", "!=", "")->where("workflow_leads.direct_phone", "not like", "%Not Publicly%")->whereRaw("workflow_leads.direct_phone REGEXP '[0-9]{10}'");
        })->orWhere(function ($input) {
            $input->whereNotNull("workflow_leads.input_phone")->where("workflow_leads.input_phone", "!=", "")->where("workflow_leads.input_phone", "not like", "%Not Publicly%")->whereRaw("workflow_leads.input_phone REGEXP '[0-9]{10}'");
        });
    });
echo "joined_dialer_sim=".$joined->count()."\n";

// pool assigned filter from service if any
$svcPath = "app/Services/Communications/DialerImportedLeadsService.php";
'''


def main() -> int:
    ssh = connect()
    b64 = base64.b64encode(PHP.encode()).decode()
    print(sudo_run(ssh, f"echo {b64} | base64 -d > /tmp/_probe_gap.php && cd {REMOTE_APP} && sudo -u www-data php /tmp/_probe_gap.php; rm -f /tmp/_probe_gap.php", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
