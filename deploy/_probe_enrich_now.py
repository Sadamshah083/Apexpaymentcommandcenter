#!/usr/bin/env python3
"""Probe enrichment status on production."""

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

echo "QUEUE_WORKERS=" . env('QUEUE_WORKERS') . "\n";
echo "OPENROUTER_MODEL=" . env('OPENROUTER_MODEL') . "\n";
echo "WORKFLOW_OPENROUTER_MODEL=" . env('WORKFLOW_OPENROUTER_MODEL') . "\n";
echo "GEMINI_KEY=" . (env('GEMINI_API_KEY') || env('GOOGLE_AI_API_KEY') ? 'yes' : 'no') . "\n";
echo "OPENROUTER_KEY=" . (env('OPENROUTER_API_KEY') ? 'yes' : 'no') . "\n";
echo "cfg_rpm=" . config('workflow_enrichment.openrouter_fallback_rpm') . "\n";
echo "cfg_retry=" . config('workflow_enrichment.openrouter_retry_delay_seconds') . "\n";
echo "cfg_or_model=" . config('workflow_enrichment.openrouter_model') . "\n";
echo "cfg_gemini=" . config('workflow_enrichment.gemini_model') . "\n";

$wfs = App\Models\Workflow::where('workspace_id', 2)
    ->where(function ($q) {
        $q->where('original_filename', 'like', '%Auto%')
          ->orWhere('name', 'like', '%Auto%');
    })
    ->orderByDesc('id')
    ->limit(8)
    ->get();

foreach ($wfs as $w) {
    $enriched = App\Models\WorkflowLead::where('workflow_id', $w->id)->whereNotNull('researched_at')->count();
    $failed = App\Models\WorkflowLead::where('workflow_id', $w->id)->where('status', 'failed')->count();
    $pending = App\Models\WorkflowLead::where('workflow_id', $w->id)->whereNull('researched_at')->count();
    $assigned = App\Models\WorkflowLead::where('workflow_id', $w->id)->whereNotNull('assigned_user_id')->count();
    echo "wf{$w->id} status={$w->status} file={$w->original_filename} total={$w->total_leads} processed={$w->processed_leads} failed_col={$w->failed_leads} enriched={$enriched} pending={$pending} assigned={$assigned}\n";
}

echo "jobs=" . DB::table('jobs')->count() . " failed_jobs=" . DB::table('failed_jobs')->count() . "\n";
$recent = DB::table('failed_jobs')->orderByDesc('id')->limit(2)->get(['id', 'failed_at', 'exception']);
foreach ($recent as $j) {
    echo "fail{$j->id} @" . $j->failed_at . " " . substr(preg_replace('/\s+/', ' ', $j->exception), 0, 220) . "\n";
}

$jobSample = DB::table('jobs')->orderBy('id')->limit(3)->get(['id', 'queue', 'payload', 'available_at', 'created_at']);
foreach ($jobSample as $j) {
    $payload = json_decode($j->payload, true);
    $display = $payload['displayName'] ?? '?';
    echo "job{$j->id} q={$j->queue} {$display}\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_enr.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_enr.php", check=False))
        print("---workers---")
        print(sudo_run(ssh, "ps aux | grep -E 'queue:work|horizon' | grep -v grep | head -20", check=False))
        print("---env snippets---")
        print(sudo_run(ssh, "grep -E '^(QUEUE_WORKERS|OPENROUTER|GEMINI|GOOGLE_AI|WORKFLOW_)' /var/www/apexone/.env | sed 's/=.*/=***/' ", check=False))
        print(sudo_run(ssh, "grep -E '^(QUEUE_WORKERS|OPENROUTER_MODEL|WORKFLOW_OPENROUTER|WORKFLOW_GEMINI|WORKFLOW_OPENROUTER_FALLBACK)' /var/www/apexone/.env", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
