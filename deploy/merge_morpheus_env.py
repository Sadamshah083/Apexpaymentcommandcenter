#!/usr/bin/env python3
"""Append missing MORPHEUS_* keys to production .env (never overwrites existing keys)."""

from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch

ENV_PATH = f"{REMOTE_APP}/.env"

DEFAULTS = {
    "MORPHEUS_HOST": "apexone.morpheus.cx",
    "MORPHEUS_PORTAL_URL": "https://apexone.morpheus.cx/",
    "MORPHEUS_DIAL_METHOD": "sip",
    "MORPHEUS_SIP_PARAMS": "user=phone",
    "MORPHEUS_OUTBOUND_PREFIX": "",
    "MORPHEUS_SIP_HOST": "",
}


def sudo_cat(ssh: paramiko.SSHClient, path: str) -> str:
    cmd = f"echo btdev | sudo -S cat {shlex.quote(path)}"
    _, stdout, _ = ssh.exec_command(cmd)
    stdout.channel.recv_exit_status()
    return stdout.read().decode(errors="replace")


def sudo_write(ssh: paramiko.SSHClient, path: str, content: str) -> None:
    tmp = "/tmp/apexone.env.merged"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(content)
    sftp.close()
    sudo_run_batch(ssh, [
        f"cp {tmp} {path}",
        f"chown www-data:www-data {path}",
        f"chmod 640 {path}",
    ])


def parse_keys(text: str) -> set[str]:
    keys: set[str] = set()
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        keys.add(line.split("=", 1)[0].strip())
    return keys


def main() -> int:
    api_key = os.environ.get("MORPHEUS_API_KEY", "").strip()
    ssh = connect()

    existing_text = sudo_cat(ssh, ENV_PATH)
    present = parse_keys(existing_text)

    additions: list[str] = []
    for key, value in DEFAULTS.items():
        if key not in present:
            additions.append(f"{key}={value}")

    if "MORPHEUS_API_KEY" not in present and api_key:
        additions.append(f"MORPHEUS_API_KEY={api_key}")

    if not additions:
        print("No Morpheus env keys to add (all present).")
    else:
        merged = existing_text.rstrip() + "\n\n# Morpheus CX\n" + "\n".join(additions) + "\n"
        sudo_write(ssh, ENV_PATH, merged)
        print(f"Added {len(additions)} key(s): {', '.join(line.split('=')[0] for line in additions)}")

    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
    ])
    ssh.close()
    print("Config cache refreshed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
