#!/usr/bin/env python3
"""Deploy owner-name detection fix + backfill Auto Services leads."""
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
from deploy._ssh import connect, sudo_run, upload_files

BACKFILL = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WorkflowLead;
use App\Support\LeadContactDisplay;

$updated = 0;
$checked = 0;
WorkflowLead::query()
    ->whereNotNull('raw_row')
    ->orderBy('id')
    ->chunkById(200, function ($leads) use (&$updated, &$checked) {
        foreach ($leads as $lead) {
            $checked++;
            $display = LeadContactDisplay::for($lead);
            $resolved = $display['owner'] ?? null;
            if (! filled($resolved)) {
                continue;
            }
            $current = trim((string) ($lead->owner_name ?? ''));
            $needsFix = $current === ''
                || LeadContactDisplay::looksLikePhoneNumber($current)
                || strcasecmp($current, $resolved) !== 0;
            if (! $needsFix) {
                continue;
            }
            // Only rewrite when current is empty/phone, or resolved differs and current is phone-like / missing person letters.
            if ($current !== '' && ! LeadContactDisplay::looksLikePhoneNumber($current) && preg_match('/[A-Za-z]{2,}/', $current)) {
                // Keep a real stored name; display already prefers resolved when phone.
                if (strcasecmp($current, $resolved) === 0) {
                    continue;
                }
            }
            if ($current === '' || LeadContactDisplay::looksLikePhoneNumber($current)) {
                $lead->owner_name = $resolved;
                $lead->save();
                $updated++;
            }
        }
    });

echo "checked={$checked} updated={$updated}\n";

// Sample Auto Services rows
$samples = WorkflowLead::query()
    ->where(function ($q) {
        $q->where('business_name', 'like', '%Auto%')
            ->orWhere('business_name', 'like', '%Tire%')
            ->orWhere('business_name', 'like', '%Muffler%');
    })
    ->orderByDesc('id')
    ->limit(8)
    ->get(['id', 'business_name', 'owner_name', 'input_phone', 'raw_row']);

foreach ($samples as $lead) {
    $d = LeadContactDisplay::for($lead);
    echo "id={$lead->id} biz={$lead->business_name} owner_db=".($lead->owner_name ?: '-')." owner_ui=".($d['owner'] ?: '-')." phone=".($d['phone'] ?: '-')."\n";
}
'''

backfill = ROOT / "deploy" / "_backfill_owner_names.php"
backfill.write_text(BACKFILL, encoding="utf-8", newline="\n")

FILES = [
    "app/Support/LeadContactDisplay.php",
    "app/Services/Workflow/WorkflowAiMapper.php",
    "app/Jobs/ProcessWorkflowJob.php",
    "tests/Unit/Support/LeadContactDisplayTest.php",
    "deploy/_backfill_owner_names.php",
]

ssh = connect()
upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
print(
    sudo_run(
        ssh,
        "cd /var/www/apexone && "
        "php -l app/Support/LeadContactDisplay.php && "
        "php -l app/Services/Workflow/WorkflowAiMapper.php && "
        "php -l app/Jobs/ProcessWorkflowJob.php && "
        "php artisan cache:clear && php artisan view:clear && "
        "./vendor/bin/phpunit --filter LeadContactDisplayTest 2>&1 | tail -n 30 && "
        "sudo -u www-data php deploy/_backfill_owner_names.php",
    )
)
ssh.close()
print("DONE")
