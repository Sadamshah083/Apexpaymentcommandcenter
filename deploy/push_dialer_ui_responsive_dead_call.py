#!/usr/bin/env python3
"""Deploy dialer responsive UI + Dead Call disposition + 0s connected call data."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]

TARGETS = [
    {
        "name": "OLD",
        "host": "203.215.160.44",
        "user": "issac",
        "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
    },
    {
        "name": "NEW",
        "host": "203.215.161.236",
        "user": "ateg",
        "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
    },
]

REMOTE = "/var/www/apexone"
FILES = [
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/css/comm-hub-ui-polish.css",
    "resources/js/communications-dialer.js",
    "resources/js/communications-auto-dial.js",
    "config/integrations.php",
    "app/Support/DispositionTone.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
]


def deploy_one(target: dict) -> bool:
    print(f"\n=== Deploy → {target['name']} ({target['host']}) ===", flush=True)
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        ssh.connect(
            target["host"],
            username=target["user"],
            password=target["password"],
            timeout=35,
        )
    except Exception as exc:
        print(f"CONNECT_FAIL: {exc}", flush=True)
        return False

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = target["host"]
    ssh_mod.USER = target["user"]
    ssh_mod.PASSWORD = target["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)
    print("UPLOADED", flush=True)

    inner = r"""
set -e
cd /var/www/apexone
grep -n "Dead Call" config/integrations.php | head -3
grep -n "ghl-phone-panel-switch--recordings" resources/views/communications/partials/center-dialer-hub.blade.php | head -3
grep -n "green → yellow\|linear-gradient(160deg, #d1fae5" resources/css/comm-hub-ui-polish.css | head -3 || true
grep -n "connectedFlag\|call_result" app/Services/Communications/CommunicationsCallHistoryService.php | head -8
./node_modules/.bin/vite build
echo BUILD_EXIT:$?
chown -R www-data:www-data public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
echo DONE
"""
    cmd = f"echo {shlex.quote(target['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=420)
    print(o.read().decode(errors="replace"))
    err = e.read().decode(errors="replace")
    if err.strip():
        print("---stderr---")
        print(err[-2500:])
    ssh.close()
    return True


def main() -> int:
    ok_any = False
    for target in TARGETS:
        if deploy_one(target):
            ok_any = True
    return 0 if ok_any else 1


if __name__ == "__main__":
    raise SystemExit(main())
