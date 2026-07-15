#!/usr/bin/env python3
"""Probe recent workflow lead assignments vs dialer visibility."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Models\\WorkflowLead;
use App\\Models\\User;
use Illuminate\\Support\\Facades\\DB;

echo "=== Recent assigned leads (last 200) ===\\n";
$rows = WorkflowLead::query()
    ->whereNotNull("assigned_user_id")
    ->orderByDesc("updated_at")
    ->limit(20)
    ->get(["id","workflow_id","assigned_user_id","assigned_setter_id","pipeline_phase","setter_status","status","normalized_phone","direct_phone","input_phone","last_contacted_at","contact_attempts","business_name","updated_at"]);

foreach ($rows as $r) {{
    $digits = preg_replace("/\\D/", "", (string)($r->normalized_phone ?: $r->direct_phone ?: $r->input_phone));
    echo sprintf(
        "#%d wf=%d user=%s setter=%s phase=%s setstat=%s status=%s digits=%s len=%d contacted=%s attempts=%s %s\\n",
        $r->id,
        $r->workflow_id,
        $r->assigned_user_id,
        $r->assigned_setter_id,
        $r->pipeline_phase,
        $r->setter_status,
        $r->status,
        substr($digits, -10),
        strlen($digits),
        $r->last_contacted_at ?: "-",
        $r->contact_attempts,
        substr((string)$r->business_name, 0, 40)
    );
}}

echo "\\n=== Counts by assignee (recent workflow max id) ===\\n";
$wf = WorkflowLead::query()->whereNotNull("assigned_user_id")->orderByDesc("updated_at")->value("workflow_id");
echo "workflow_id=$wf\\n";
if ($wf) {{
    $counts = WorkflowLead::query()
        ->where("workflow_id", $wf)
        ->whereNotNull("assigned_user_id")
        ->selectRaw("assigned_user_id, count(*) as c")
        ->groupBy("assigned_user_id")
        ->get();
    foreach ($counts as $c) {{
        $u = User::find($c->assigned_user_id);
        echo sprintf("user=%d (%s) assigned=%d\\n", $c->assigned_user_id, $u?->name ?? "?", $c->c);
    }}
    $dialable = WorkflowLead::query()
        ->where("workflow_id", $wf)
        ->whereNotNull("assigned_user_id")
        ->whereNull("last_contacted_at")
        ->where(function ($q) {{
            $q->whereNull("contact_attempts")->orWhere("contact_attempts", "<=", 0);
        }})
        ->where(function ($phone) {{
            $phone->where(function ($n) {{
                $n->whereNotNull("normalized_phone")->where("normalized_phone", "!=", "")->whereRaw("normalized_phone REGEXP \\\"[0-9]{{10}}\\\"");
            }})->orWhere(function ($d) {{
                $d->whereNotNull("direct_phone")->where("direct_phone", "!=", "")->where("direct_phone", "not like", "%Not Publicly%")->whereRaw("direct_phone REGEXP \\\"[0-9]{{10}}\\\"");
            }})->orWhere(function ($i) {{
                $i->whereNotNull("input_phone")->where("input_phone", "!=", "")->where("input_phone", "not like", "%Not Publicly%")->whereRaw("input_phone REGEXP \\\"[0-9]{{10}}\\\"");
            }});
        }})
        ->selectRaw("assigned_user_id, count(*) as c")
        ->groupBy("assigned_user_id")
        ->get();
    echo "\\n=== Dialer-visible (phone + untouched) ===\\n";
    foreach ($dialable as $c) {{
        $u = User::find($c->assigned_user_id);
        echo sprintf("user=%d (%s) dialable=%d\\n", $c->assigned_user_id, $u?->name ?? "?", $c->c);
    }}
    $noPhone = WorkflowLead::query()
        ->where("workflow_id", $wf)
        ->whereNotNull("assigned_user_id")
        ->where(function ($phone) {{
            $phone->where(function ($q) {{
                $q->whereNull("normalized_phone")->orWhere("normalized_phone", "")->orWhereRaw("normalized_phone NOT REGEXP \\\"[0-9]{{10}}\\\"");
            }})->where(function ($q) {{
                $q->whereNull("direct_phone")->orWhere("direct_phone", "")->orWhere("direct_phone", "like", "%Not Publicly%")->orWhereRaw("direct_phone NOT REGEXP \\\"[0-9]{{10}}\\\"");
            }})->where(function ($q) {{
                $q->whereNull("input_phone")->orWhere("input_phone", "")->orWhere("input_phone", "like", "%Not Publicly%")->orWhereRaw("input_phone NOT REGEXP \\\"[0-9]{{10}}\\\"");
            }});
        }})
        ->count();
    echo "assigned_without_dialable_phone=$noPhone\\n";
    $totalAssigned = WorkflowLead::query()->where("workflow_id", $wf)->whereNotNull("assigned_user_id")->count();
    $unassigned = WorkflowLead::query()->where("workflow_id", $wf)->whereNull("assigned_user_id")->count();
    echo "total_assigned=$totalAssigned unassigned=$unassigned\\n";
}}
'
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
