#!/usr/bin/env python3
"""Re-upload watchdog + verify kill recovery."""
from __future__ import annotations

import os
import shlex
import sys
import time
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
HOST, USER, PW = "203.215.161.236", "ateg", "balitech1"
APP = "/var/www/apexone"


def sudo(ssh, cmd: str, timeout: int = 90) -> str:
    full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=timeout)
    return (o.read() + e.read()).decode(errors="replace")


def main() -> int:
    # Ensure LF
    p = ROOT / "scripts/apexone_watchdog.sh"
    p.write_bytes(p.read_bytes().replace(b"\r\n", b"\n").replace(b"\r", b"\n"))

    sys.path.insert(0, str(ROOT))
    os.environ["DEPLOY_PASSWORD"] = PW
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = HOST
    ssh_mod.USER = USER
    ssh_mod.PASSWORD = PW
    ssh_mod.REMOTE_APP = APP
    from deploy._ssh import upload_files

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PW, timeout=30)
    upload_files(ssh, [(p, "scripts/apexone_watchdog.sh")], app_root=APP)
    print(sudo(ssh, f"sed -i 's/\\r$//' {APP}/scripts/apexone_watchdog.sh && chmod +x {APP}/scripts/apexone_watchdog.sh && bash {APP}/scripts/apexone_watchdog.sh"))

    print("=== kill websocket process (systemd Restart=always should revive) ===")
    print(sudo(ssh, "systemctl kill -s SIGKILL apex-call-events-ws || true; sleep 2; systemctl is-active apex-call-events-ws; curl -fsS http://127.0.0.1:8787/health || echo ws_down"))

    print("=== stop queue (watchdog should start it) ===")
    print(sudo(ssh, "systemctl stop apexone-queue; systemctl is-active apexone-queue || true"))
    time.sleep(35)
    print(sudo(ssh, "systemctl is-active apexone-queue; systemctl start apexone-watchdog.service; sleep 2; systemctl is-active apexone-queue; tail -n 15 /var/www/apexone/storage/logs/apexone-watchdog.log"))

    print("=== final health ===")
    print(sudo(ssh, "curl -sk -o /dev/null -w 'up=%{http_code}\\n' -H 'Host: crm.apexonepayments.com' https://127.0.0.1/up; curl -fsS http://127.0.0.1:8787/health; echo; systemctl is-active nginx php8.3-fpm apexone-queue apex-call-events-ws apexone-watchdog.timer"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
