#!/usr/bin/env python3
from __future__ import annotations

import shlex

import paramiko

NEW_HOST = "203.215.161.236"
NEW_USER = "ateg"
NEW_PW = "balitech1"
DOMAIN = "crm.apexonepayments.com"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW_HOST, username=NEW_USER, password=NEW_PW, timeout=30)

    def sudo(cmd: str) -> str:
        full = f"echo {shlex.quote(NEW_PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
        _, stdout, stderr = ssh.exec_command(full, timeout=60)
        return (stdout.read() + stderr.read()).decode(errors="replace")

    print(
        sudo(
            f"""
curl -sk -o /tmp/p.html -w 'portal_login=%{{http_code}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/portal/login
grep -oE 'Agent sign in|ApexOne Command Center|username|password|Sign in' /tmp/p.html | sort -u
curl -sk -o /dev/null -w 'root=%{{http_code}} redir=%{{redirect_url}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/
ss -lptn 'sport = :8787' | head -3
systemctl is-active apexone-queue apex-call-events-ws nginx
"""
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
