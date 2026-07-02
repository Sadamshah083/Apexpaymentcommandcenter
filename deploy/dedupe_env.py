#!/usr/bin/env python3
"""Deduplicate .env keys on server (keeps first occurrence)."""

from __future__ import annotations

import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch

ENV_PATH = f"{REMOTE_APP}/.env"


def run(ssh: paramiko.SSHClient, command: str) -> str:
    _, stdout, stderr = ssh.exec_command(command)
    stdout.channel.recv_exit_status()
    return stdout.read().decode(errors="replace")


def main() -> int:
    ssh = connect()
    raw = run(ssh, f"sudo cat {ENV_PATH}")
    seen: set[str] = set()
    out_lines: list[str] = []
    dupes = 0
    for line in raw.splitlines():
        if "=" in line and not line.strip().startswith("#"):
            key = line.split("=", 1)[0].strip()
            if key in seen:
                dupes += 1
                continue
            seen.add(key)
        out_lines.append(line)

    if dupes == 0:
        print("No duplicate keys found.")
        ssh.close()
        return 0

    content = "\n".join(out_lines) + "\n"
    tmp = "/tmp/apexone.env.dedup"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f:
        f.write(content)
    sftp.close()

    sudo_run_batch(ssh, [
        f"cp {tmp} {ENV_PATH}",
        f"chown www-data:www-data {ENV_PATH}",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
    ])
    ssh.close()
    print(f"Removed {dupes} duplicate key line(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
