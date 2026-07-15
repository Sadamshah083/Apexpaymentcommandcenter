#!/usr/bin/env python3
"""Probe why some assigned leads still aren't dialer-visible after backfill."""

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

use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\LeadDialablePhone;
use App\Services\Communications\DialerImportedLeadsService;

$ws = Workspace::where("name", "ApexPayments")->first();
$svc = app(DialerImportedLeadsService::class);

echo "=== Recent assignments (last 24h by assignee) ===\n";
$rows = WorkflowLead::query()
    ->selectRaw("assigned_user_id, count(*) as c, max(updated_at) as last_at")
    ->whereNotNull("assigned_user_id")
    ->where("updated_at", ">=", now()->subDay())
    ->groupBy("assigned_user_id")
    ->orderByDesc("c")
    ->get();
foreach ($rows as $r) {
    $u = User::find($r->assigned_user_id);
    $dial = $svc->paginate($ws, ["pool"=>"assigned","assigned_user_id"=>(int)$r->assigned_user_id], 0, 1);
    echo ($u->name ?? $r->assigned_user_id)." id={$r->assigned_user_id} assigned_24h={$r->c} dialer_total={$dial['total']} last={$r->last_at}\n";
}

echo "\n=== Elijah non-dialable sample ===\n";
$non = WorkflowLead::query()
    ->where("assigned_user_id", 14)
    ->where(function ($q) {
        $q->whereNull("normalized_phone")->orWhere("normalized_phone", "")
          ->orWhereRaw("normalized_phone not regexp '[0-9]{10}'");
    })
    ->where(function ($q) {
        $q->whereNull("direct_phone")->orWhere("direct_phone", "")
          ->orWhere("direct_phone", "like", "%Not Publicly%")
          ->orWhereRaw("direct_phone not regexp '[0-9]{10}'");
    })
    ->limit(5)
    ->get(["id","direct_phone","input_phone","normalized_phone","markdown_report","raw_row","business_name"]);

foreach ($non as $lead) {
    $resolved = LeadDialablePhone::resolve($lead);
    $md = (string)($lead->markdown_report ?? "");
    preg_match_all('/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $md, $m);
    $raw = is_array($lead->raw_row) ? $lead->raw_row : [];
    $contact = $raw["Contact No."] ?? $raw["phone"] ?? "";
    echo "#{$lead->id} biz=".substr((string)$lead->business_name,0,40)." resolve=".($resolved?:'null')." md_matches=".count($m[0]??[])." contact=[".$contact."] d=[".$lead->direct_phone."] n=[".$lead->normalized_phone."]\n";
}

$missing = WorkflowLead::query()->where("assigned_user_id", 14)
    ->where(function ($q) {
        $q->whereNull("normalized_phone")->orWhere("normalized_phone", "")
          ->orWhereRaw("normalized_phone not regexp '[0-9]{10}'");
    })
    ->where(function ($q) {
        $q->whereNull("direct_phone")->orWhere("direct_phone", "")
          ->orWhere("direct_phone", "like", "%Not Publicly%")
          ->orWhereRaw("direct_phone not regexp '[0-9]{10}'");
    })->count();
echo "elijah_still_no_dialable_cols={$missing}\n";
'''


def main() -> int:
    ssh = connect()
    b64 = base64.b64encode(PHP.encode()).decode()
    print(
        sudo_run(
            ssh,
            f"echo {b64} | base64 -d > /tmp/_probe_after.php && cd {REMOTE_APP} && sudo -u www-data php /tmp/_probe_after.php; rm -f /tmp/_probe_after.php",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
