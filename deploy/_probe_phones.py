#!/usr/bin/env python3
"""Inspect phone data on assigned leads (base64 to avoid shell expansion)."""

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
use App\Models\User;

$lead = WorkflowLead::find(3111);
echo "lead 3111:\n";
echo "direct=" . var_export($lead->direct_phone, true) . "\n";
echo "input=" . var_export($lead->input_phone, true) . "\n";
echo "norm=" . var_export($lead->normalized_phone, true) . "\n";
$raw = is_array($lead->raw_row) ? $lead->raw_row : [];
echo "raw_keys=" . implode(",", array_keys($raw)) . "\n";
foreach ($raw as $k => $v) {
    $ks = (string) $k;
    if (stripos($ks, "phone") !== false || stripos($ks, "mobile") !== false || stripos($ks, "tel") !== false || stripos($ks, "cell") !== false) {
        echo "raw[$ks]=" . (is_scalar($v) ? $v : json_encode($v)) . "\n";
    }
}
$u = User::find(14);
echo "user14=" . ($u->name ?? "?") . " role=" . $u->getWorkspaceRole() . "\n";
$portal = WorkflowLead::query()->where("pipeline_phase", "with_setter")->where("assigned_user_id", 14)->count();
echo "portal_visible_for_elijah=$portal\n";

$withAnyPhone = WorkflowLead::query()
    ->where("workflow_id", 13)
    ->where(function ($q) {
        $q->where(function ($n) {
            $n->whereNotNull("normalized_phone")->where("normalized_phone", "!=", "");
        })->orWhere(function ($d) {
            $d->whereNotNull("direct_phone")->where("direct_phone", "!=", "");
        })->orWhere(function ($i) {
            $i->whereNotNull("input_phone")->where("input_phone", "!=", "");
        });
    })->count();
echo "wf13_with_any_phone_col=$withAnyPhone\n";

$samples = WorkflowLead::query()->where("workflow_id", 13)->orderBy("id")->limit(8)->get(["id","direct_phone","input_phone","normalized_phone","raw_row","markdown_report"]);
foreach ($samples as $s) {
    $raw = is_array($s->raw_row) ? $s->raw_row : [];
    $phoneish = [];
    foreach ($raw as $k => $v) {
        if (preg_match('/phone|mobile|tel|cell/i', (string)$k) && is_scalar($v) && trim((string)$v) !== '') {
            $phoneish[] = "$k=$v";
        }
    }
    $md = (string) ($s->markdown_report ?? "");
    preg_match_all('/\+?1?[\s\-.]?\(?\d{3}\)?[\s\-.]?\d{3}[\s\-.]?\d{4}/', $md, $m);
    echo "#{$s->id} d=[" . ($s->direct_phone ?: '') . "] i=[" . ($s->input_phone ?: '') . "] n=[" . ($s->normalized_phone ?: '') . "] raw_phone=[" . implode("; ", $phoneish) . "] md_phones=[" . implode(",", array_slice($m[0] ?? [], 0, 3)) . "]\n";
}
'''


def main() -> int:
    ssh = connect()
    b64 = base64.b64encode(PHP.encode()).decode()
    print(
        sudo_run(
            ssh,
            f"echo {b64} | base64 -d > /tmp/_probe_phones.php && cd {REMOTE_APP} && sudo -u www-data php /tmp/_probe_phones.php; rm -f /tmp/_probe_phones.php",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
