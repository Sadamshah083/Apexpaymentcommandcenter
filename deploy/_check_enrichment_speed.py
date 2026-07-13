#!/usr/bin/env python3
from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect


def main() -> int:
    ssh = connect()
    cmd = f"""
cd {REMOTE_APP}
grep -E '^(DB_CONNECTION|QUEUE_WORKERS|WORKFLOW_WEB_SEARCH|WORKFLOW_FOLLOW|WORKFLOW_GEMINI_GOOGLE)' .env || true
echo ---
pgrep -af 'queue:(work|pool)' || true
echo ---
sudo -u www-data php artisan tinker --execute="echo json_encode(['queries'=>config('workflow_enrichment.web_search_queries'),'follow_up'=>config('workflow_enrichment.follow_up_enabled'),'google'=>config('workflow_enrichment.gemini_google_search_enabled'),'workers'=>config('queue.workers')]);"
"""
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full)
    print(stdout.read().decode(errors="replace"))
    err = stderr.read().decode(errors="replace")
    if err.strip():
        print(err)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
