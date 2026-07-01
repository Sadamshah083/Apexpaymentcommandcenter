#!/usr/bin/env python3
"""Hotfix deploy enrichment files + retry failed leads only."""

from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

WORKFLOW_ID = int(__import__("os").environ.get("WORKFLOW_ID", "1"))

UPLOADS = [
    "app/Services/BusinessResearch/MarkdownReportParser.php",
    "app/Services/Workflow/WorkflowExtractor.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in UPLOADS]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    php = f"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$workflowId = {WORKFLOW_ID};
$workflow = App\\Models\\Workflow::find($workflowId);
$failedIds = App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'failed')->pluck('id');
$stuckIds = App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'extracting')
    ->where('updated_at', '<', now()->subMinutes(10))->pluck('id');
$retryIds = $failedIds->merge($stuckIds)->unique();
App\\Models\\WorkflowLead::whereIn('id', $retryIds)->update(['status' => 'imported', 'error_message' => null]);
if ($workflow) {{ $workflow->update(['status' => 'extracting']); }}
foreach ($retryIds as $leadId) {{
    App\\Jobs\\ProcessLeadJob::dispatch($leadId, $workflow?->custom_prompt);
}}
echo 'retried_' . $retryIds->count();
"""
    restart_queue_workers(ssh)
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php -r {shlex.quote(php)}"))
    ssh.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"HOTFIX FAILED: {exc}", file=sys.stderr)
        raise
