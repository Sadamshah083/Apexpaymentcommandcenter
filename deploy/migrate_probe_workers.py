#!/usr/bin/env python3
"""Find websocket :8787 and queue workers on old server."""

from __future__ import annotations

import shlex

import paramiko

HOST = "203.215.160.44"
USER = "issac"
PW = "SadamShah123"


def sudo_run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 180) -> str:
    full = f"echo {shlex.quote(PW)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    lines = [ln for ln in (out + err).splitlines() if "password for" not in ln.lower()]
    return "\n".join(lines).strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PW, timeout=30)

    print("===== :8787 =====")
    print(sudo_run(ssh, "ss -lptn 'sport = :8787' 2>/dev/null; ss -lptn | grep 8787; ps aux | grep -E '8787|communications-ws|queue:work|horizon|artisan' | grep -v grep"))

    print("\n===== systemd units =====")
    print(sudo_run(ssh, "systemctl list-units --type=service --all | grep -Ei 'apex|queue|ws|comm|laravel' ; ls /etc/systemd/system/*apex* /etc/systemd/system/*queue* /etc/systemd/system/*ws* 2>/dev/null; ls /var/www/apexone/services 2>/dev/null"))

    print("\n===== services dir =====")
    print(sudo_run(ssh, "find /var/www/apexone/services -maxdepth 3 -type f 2>/dev/null | head -40; ls -la /var/www/apexone/scripts 2>/dev/null | head"))

    print("\n===== crontab =====")
    print(sudo_run(ssh, "crontab -l 2>/dev/null; ls /etc/cron.d | head; cat /etc/cron.d/*apex* /etc/cron.d/*laravel* 2>/dev/null; grep -R queue /etc/systemd/system 2>/dev/null | head"))

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
