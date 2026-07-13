#!/usr/bin/env python3
"""Deploy mapping UI polish + faster enrichment settings."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, set_env_vars, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/views/workflows/show.blade.php",
    "resources/css/app.css",
    "resources/js/app.js",
    "resources/js/pretty-select.js",
    "resources/js/workflow-upload.js",
    "app/Services/Workflow/WorkflowExtractor.php",
    "app/Services/BusinessResearch/WebSearchService.php",
    "config/workflow_enrichment.php",
    "deploy/configure_enrichment.py",
]

ENV_UPDATES = {
    "WORKFLOW_WEB_SEARCH_QUERIES": "0",
    "WORKFLOW_FOLLOW_UP_ENABLED": "false",
    "WORKFLOW_GEMINI_GOOGLE_SEARCH": "true",
    "WORKFLOW_GEMINI_TIMEOUT": "90",
    "WORKFLOW_GEMINI_THINKING_BUDGET": "0",
    "QUEUE_WORKERS": "6",
}


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Updating enrichment env for speed...")
    set_env_vars(ssh, ENV_UPDATES)
    print("Building assets + clearing caches...")
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
    ])
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    print(sudo_run(ssh, f"curl -fsS http://203.215.160.44/admin/login -o /dev/null -w '%{{http_code}}'"))
    ssh.close()
    print("Mapping UI + enrichment speed fix deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
