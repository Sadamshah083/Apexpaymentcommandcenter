<?php
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
