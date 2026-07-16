#!/usr/bin/env python3
from __future__ import annotations

import shlex

import paramiko

PW = "SadamShah123"


def main() -> None:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect("203.215.160.44", username="issac", password=PW, timeout=30)

    def run(cmd: str) -> str:
        full = f"echo {shlex.quote(PW)} | sudo -S bash -lc {shlex.quote(cmd)}"
        _, stdout, stderr = ssh.exec_command(full, timeout=60)
        text = (stdout.read() + stderr.read()).decode(errors="replace")
        return "\n".join(ln for ln in text.splitlines() if "password for" not in ln.lower())

    for path in (
        "/etc/systemd/system/apexone-queue.service",
        "/etc/systemd/system/apex-call-events-ws.service",
        "/etc/systemd/system/apexone-comm-hub-monitor.service",
        "/etc/systemd/system/apexone-comm-hub-monitor.timer",
    ):
        print(f"===== {path} =====")
        print(run(f"cat {path} 2>/dev/null || echo MISSING"))
        print()

    ssh.close()


if __name__ == "__main__":
    main()
