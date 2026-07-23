#!/usr/bin/env python3
"""Remove Admin workspace label + refresh Gemini health after billing top-up."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/views/auth/login_admin.blade.php",
    "resources/views/workflows/partials/enrichment-status.blade.php",
    "app/Services/Workflow/WorkflowProviderStatusService.php",
]

CLEAR = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Support\Facades\Cache;

$ps = app(WorkflowProviderStatusService::class);
$ps->clearGeminiError();
Cache::forget('workflow-enrichment:openrouter-daily-exhausted');
Cache::forget('workflow.gemini_health');
Cache::forget('workflow.gemini_last_error');
Cache::forget('workflow.openrouter_balance');
Cache::forget('workflow.openrouter_auth');

$status = $ps->getEnrichmentStatus(true, true);
echo "gemini_state=" . ($status['gemini']['state'] ?? '?') . "\n";
echo "gemini_label=" . ($status['gemini']['label'] ?? '?') . "\n";
echo "openrouter_state=" . ($status['openrouter']['state'] ?? '?') . "\n";
echo "openrouter_label=" . ($status['openrouter']['label'] ?? '?') . "\n";
echo "openrouter_message=" . substr((string) ($status['openrouter']['message'] ?? ''), 0, 200) . "\n";

// Prefer OpenRouter live ping (Gemini still depleted on this project key)
try {
    $or = app(App\Services\BusinessResearch\OpenRouterClient::class);
    $r = $or->chatForPipeline('Reply with exactly OK', 'Say OK');
    $content = is_array($r) ? ($r['content'] ?? json_encode($r)) : (string) $r;
    echo "openrouter_ping=ok model=" . ($r['model'] ?? '?') . " content=" . substr(preg_replace('/\s+/', ' ', $content), 0, 60) . "\n";
} catch (Throwable $e) {
    echo "openrouter_ping_error=" . substr($e->getMessage(), 0, 300) . "\n";
}

try {
    $g = app(App\Services\BusinessResearch\GeminiClient::class);
    $r = $g->researchWithGoogleSearch('Reply with exactly OK', 'Say OK', [
        'model' => (string) config('workflow_enrichment.gemini_model', 'gemini-2.5-flash'),
        'google_search_enabled' => false,
        'thinking_budget' => 0,
        'max_output_tokens' => 16,
        'timeout' => 45,
    ]);
    echo "gemini_ping=ok\n";
    $ps->clearGeminiError();
} catch (Throwable $e) {
    echo "gemini_ping_error=" . substr($e->getMessage(), 0, 180) . "\n";
}
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    local = ROOT / "deploy" / "_clear_gemini_tmp.php"
    local.write_text(CLEAR, encoding="utf-8")
    upload_files(ssh, [(local, "storage/app/_clear_gemini.php")], app_root=REMOTE_APP)

    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_clear_gemini.php",
        f"rm -f {REMOTE_APP}/storage/app/_clear_gemini.php",
    ]))
    local.unlink(missing_ok=True)
    ssh.close()
    print("Login label removed + Gemini status refreshed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
