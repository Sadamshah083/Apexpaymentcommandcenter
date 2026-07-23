#!/usr/bin/env python3
"""Deploy Telescope hot-exception fixes: recording_status, disposition width, OpenRouter noise."""
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

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CommunicationsDataService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "config/openrouter.php",
    "database/migrations/2026_07_20_010000_widen_communication_call_logs_disposition.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    try:
        upload_files(ssh, pairs, REMOTE_APP)
        set_env_vars(
            ssh,
            {
                "OPENROUTER_FALLBACK_MODELS": "openai/gpt-oss-20b:free,openrouter/free,meta-llama/llama-3.3-70b-instruct",
                "OPENROUTER_CALL_SUMMARY_FALLBACK_MODELS": "openrouter/free,meta-llama/llama-3.3-70b-instruct",
            },
        )
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
sudo -u www-data php artisan migrate --force --path=database/migrations/2026_07_20_010000_widen_communication_call_logs_disposition.php
echo MIGRATE:$?
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
echo ---
grep -n 'recording_status' app/Services/Communications/CommunicationsDataService.php | head -8
grep -n mb_substr app/Services/Communications/CommunicationsCallHistoryService.php | head -3
grep -n isQuota app/Http/Controllers/AgentStatusReportController.php | head -5
mysql -N -e "SHOW COLUMNS FROM apexone.communication_call_logs LIKE 'disposition'" 2>/dev/null || true
""",
            check=False,
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        print("Hot exception fixes deployed.")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
