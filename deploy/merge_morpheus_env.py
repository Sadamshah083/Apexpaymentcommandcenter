#!/usr/bin/env python3
"""Append missing MORPHEUS_* keys to production .env without overwriting existing values."""

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


def run(ssh: paramiko.SSHClient, command: str) -> str:
    _, stdout, stderr = ssh.exec_command(command)
    stdout.channel.recv_exit_status()
    return stdout.read().decode(errors="replace")


def main() -> int:
    api_key = os.environ.get("MORPHEUS_API_KEY", "").strip()
    ssh = connect()

    existing = run(ssh, f"grep -E '^[A-Z_]+=' {ENV_PATH} 2>/dev/null | cut -d= -f1 | sort -u || true")
    present = {line.strip() for line in existing.splitlines() if line.strip()}

    to_add: list[str] = []
    for key, value in DEFAULTS.items():
        if key not in present:
            to_add.append(f"{key}={value}")

    if "MORPHEUS_API_KEY" not in present and api_key:
        to_add.append(f"MORPHEUS_API_KEY={api_key}")

    if not to_add:
        print("No Morpheus env keys to add (all present).")
    else:
        block = "\\n".join(to_add)
        cmd = (
            f"printf '%s\\n' {shlex.quote(block)} | "
            f"sudo tee -a {ENV_PATH} > /dev/null && "
            f"sudo chown www-data:www-data {ENV_PATH}"
        )
        run(ssh, cmd)
        print(f"Appended {len(to_add)} Morpheus env key(s): {', '.join(line.split('=')[0] for line in to_add)}")

    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
    ])
    ssh.close()
    print("Config cache refreshed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
