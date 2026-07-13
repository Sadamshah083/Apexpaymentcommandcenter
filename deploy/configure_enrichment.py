#!/usr/bin/env python3
"""Set Gemini/OpenRouter keys on production and optionally resume workflow enrichment."""

from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, set_env_vars, sudo_run, sudo_run_batch, upload_files

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
WORKFLOW_ID = int(os.environ.get("WORKFLOW_ID", "1"))
CLEAR_GEMINI = os.environ.get("CLEAR_GEMINI", "").lower() in {"1", "true", "yes"}
# Only re-queue failed/stuck leads by default. Set RESUME_ALL_IMPORTED=1 to queue every imported lead.
RESUME_ALL_IMPORTED = os.environ.get("RESUME_ALL_IMPORTED", "").lower() in {"1", "true", "yes"}

WORKFLOW_ENV_DEFAULTS = {
    "WORKFLOW_GEMINI_MODEL": "gemini-2.5-flash",
    "WORKFLOW_GEMINI_FALLBACK_MODELS": "gemini-2.5-pro",
    "WORKFLOW_GEMINI_MAX_OUTPUT_TOKENS": "4096",
    "WORKFLOW_GEMINI_THINKING_BUDGET": "0",
    "WORKFLOW_GEMINI_GOOGLE_SEARCH": "true",
    "WORKFLOW_GEMINI_TIMEOUT": "90",
    "WORKFLOW_WEB_SEARCH_QUERIES": "0",
    "WORKFLOW_FOLLOW_UP_ENABLED": "false",
    "WORKFLOW_FOLLOW_UP_MIN_SCORE": "3",
    "WORKFLOW_OPENROUTER_MAX_TOKENS": "4096",
    "WORKFLOW_OPENROUTER_WEB_SEARCH": "true",
}

UPLOAD_PATHS = [
    "config/workflow_enrichment.php",
    "app/Services/Workflow/WorkflowExtractor.php",
    "app/Services/Workflow/WorkflowProviderStatusService.php",
    "app/Services/BusinessResearch/ResearchInput.php",
    "app/Services/BusinessResearch/MarkdownReportParser.php",
    "app/Services/BusinessResearch/ResearchResultSanitizer.php",
    "app/Services/BusinessResearch/BusinessResearchPrompt.php",
    "app/Services/BusinessResearch/WebSearchService.php",
    "app/Services/BusinessResearch/OpenRouterClient.php",
    "app/Services/BusinessResearch/GeminiClient.php",
]


def load_local_env() -> dict[str, str]:
    path = ROOT / ".env"
    if not path.is_file():
        return {}
    values: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        values[key.strip()] = val.strip().strip('"')
    return values


def env_or_local(name: str, local: dict[str, str]) -> str:
    return os.environ.get(name, "") or local.get(name, "")


def main() -> int:
    local = load_local_env()
    gemini_api_key = env_or_local("GEMINI_API_KEY", local)
    openrouter_api_key = env_or_local("OPENROUTER_API_KEY", local)

    if not gemini_api_key and not openrouter_api_key and not CLEAR_GEMINI:
        print("Set GEMINI_API_KEY and/or OPENROUTER_API_KEY in the environment or local .env.", file=sys.stderr)
        return 1

    if CLEAR_GEMINI and not openrouter_api_key:
        print("CLEAR_GEMINI requires OPENROUTER_API_KEY as the active provider.", file=sys.stderr)
        return 1

    ssh = connect()

    env_updates: dict[str, str] = dict(WORKFLOW_ENV_DEFAULTS)
    if gemini_api_key:
        print("Configuring GEMINI_API_KEY...")
        env_updates["GEMINI_API_KEY"] = gemini_api_key
    elif CLEAR_GEMINI:
        print("Clearing GEMINI_API_KEY...")
        env_updates["GEMINI_API_KEY"] = ""
    if openrouter_api_key:
        print("Configuring OPENROUTER_API_KEY...")
        env_updates["OPENROUTER_API_KEY"] = openrouter_api_key

    print("Configuring WORKFLOW_* settings (one SSH call)...")
    set_env_vars(ssh, env_updates)

    pairs = [(ROOT / rel, rel) for rel in UPLOAD_PATHS if (ROOT / rel).is_file()]
    print(f"Uploading {len(pairs)} PHP files in one archive...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Refreshing Laravel config and queue workers...")
    sudo_run_batch(ssh, [
        f"chown www-data:www-data {REMOTE_APP}/.env",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
    ])
    restart_queue_workers(ssh)

    if RESUME_ALL_IMPORTED:
        print("RESUME_ALL_IMPORTED=1 — queueing every imported lead (slow for large workflows)...")
        resume_php = f"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$workflowId = {WORKFLOW_ID};
$workflow = App\\Models\\Workflow::find($workflowId);
if (! $workflow) {{ fwrite(STDERR, 'Workflow not found\\n'); exit(1); }}
App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'failed')->update(['status' => 'imported', 'error_message' => null]);
App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'extracting')->update(['status' => 'imported']);
$workflow->update(['status' => 'extracting', 'failed_leads' => 0, 'error_message' => null]);
app(App\\Services\\Workflow\\WorkflowService::class)->dispatchPendingLeadJobs($workflow->fresh());
echo 'queued_imported';
"""
    else:
        print("Re-queueing failed and stuck leads only (fast)...")
        resume_php = f"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$workflowId = {WORKFLOW_ID};
$workflow = App\\Models\\Workflow::find($workflowId);
if (! $workflow) {{ fwrite(STDERR, 'Workflow not found\\n'); exit(1); }}
$failedIds = App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'failed')->pluck('id');
$stuckIds = App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'extracting')
    ->where('updated_at', '<', now()->subMinutes(10))->pluck('id');
$retryIds = $failedIds->merge($stuckIds)->unique();
if ($retryIds->isNotEmpty()) {{
    App\\Models\\WorkflowLead::whereIn('id', $retryIds)->update(['status' => 'imported', 'error_message' => null]);
    $workflow->update(['status' => 'extracting', 'error_message' => null]);
    foreach ($retryIds as $leadId) {{
        App\\Jobs\\ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt);
    }}
}}
echo 'retried_' . $retryIds->count();
"""

    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php -r {shlex.quote(resume_php)}"))

    status_php = f"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo 'jobs=' . DB::table('jobs')->count();
echo ' gemini=' . (config('gemini.api_key') ? 'ok' : 'missing');
echo ' model=' . (config('workflow_enrichment.gemini_model') ?: config('gemini.model'));
"""
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php -r {shlex.quote(status_php)}"))

    ssh.close()
    print("Enrichment configure complete.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"CONFIGURE FAILED: {exc}", file=sys.stderr)
        raise
