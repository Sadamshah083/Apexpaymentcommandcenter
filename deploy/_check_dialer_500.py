#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
print(sudo_run(ssh, f"grep -n 'dialer.call-logs' {REMOTE_APP}/routes/web.php | head", check=False))
print("---")
print(sudo_run(ssh, f"grep -n 'dialerCallLogs' {REMOTE_APP}/app/Http/Controllers/CommunicationsHubController.php | head", check=False))
print("--- LAST ERROR ---")
print(sudo_run(ssh, f"grep 'local.ERROR' {REMOTE_APP}/storage/logs/laravel.log | tail -3", check=False))
print(sudo_run(ssh, f"tail -200 {REMOTE_APP}/storage/logs/laravel.log | grep -B2 'Route \\[admin' | tail -20", check=False))
ssh.close()
