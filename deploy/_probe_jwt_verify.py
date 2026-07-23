#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "SadamShah123")

import deploy._ssh as m

m.HOST = "203.215.160.44"
m.USER = "issac"
m.PASSWORD = "SadamShah123"
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

ssh = connect()
try:
    print(sudo_run(ssh, "cd /var/www/apexone && grep -E '^JWT_' .env || echo NO_JWT"))
    print(sudo_run(ssh, "cd /var/www/apexone && php deploy/_verify_jwt_errors.php 2>&1"))
    print(sudo_run(ssh, "cd /var/www/apexone && grep -n \"where('event\" app -r 2>/dev/null | head -20 || true"))
    print(sudo_run(ssh, "cd /var/www/apexone && php artisan tinker --execute=\"echo implode(',', Schema::getColumnListing('workspace_sync_events'));\" 2>&1 | tail -5"))
finally:
    ssh.close()
