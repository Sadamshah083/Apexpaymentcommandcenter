#!/usr/bin/env python3
"""Copy public/build from NEW to OLD after vite fails on OLD."""
from __future__ import annotations

import tempfile
from pathlib import Path

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
OLD = {"host": "203.215.160.44", "user": "issac", "password": "SadamShah123"}
REMOTE = "/var/www/apexone"


def connect(cfg: dict) -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=40)
    return ssh


def sudo(ssh: paramiko.SSHClient, password: str, command: str) -> str:
    import shlex

    full = f"echo {shlex.quote(password)} | sudo -S -p '' bash -lc {shlex.quote(command)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=180)
    return (stdout.read() + stderr.read()).decode(errors="replace")


def main() -> int:
    ssh_n = connect(NEW)
    print(sudo(ssh_n, NEW["password"], f"cd {REMOTE} && tar czf /tmp/apex-build.tgz public/build && chown {NEW['user']}:{NEW['user']} /tmp/apex-build.tgz"))
    local = Path(tempfile.gettempdir()) / "apex-build.tgz"
    sftp = ssh_n.open_sftp()
    sftp.get("/tmp/apex-build.tgz", str(local))
    sftp.close()
    ssh_n.close()
    print("downloaded", local.stat().st_size)

    ssh_o = connect(OLD)
    sftp = ssh_o.open_sftp()
    sftp.put(str(local), "/tmp/apex-build.tgz")
    sftp.close()
    print(sudo(ssh_o, OLD["password"], f"cd {REMOTE} && tar xzf /tmp/apex-build.tgz && chown -R www-data:www-data public/build && ls public/build/assets | wc -l"))
    ssh_o.close()
    print("OLD build synced")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
