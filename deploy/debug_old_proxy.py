#!/usr/bin/env python3
from __future__ import annotations

import shlex

import paramiko

OLD = {"host": "203.215.160.44", "user": "issac", "password": "SadamShah123"}
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
DOMAIN = "crm.apexonepayments.com"
NEW_IP = "203.215.161.236"


def connect(cfg):
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=30)
    return ssh


def sudo(ssh, password, cmd, timeout=60):
    full = f"echo {shlex.quote(password)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    return (stdout.read() + stderr.read()).decode(errors="replace")


def main():
    old = connect(OLD)
    print("=== from OLD curl NEW directly ===")
    print(
        sudo(
            old,
            OLD["password"],
            f"""
curl -sk -o /dev/null -w 'direct_https=%{{http_code}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:{NEW_IP} https://{DOMAIN}/admin/login
curl -sk -o /dev/null -w 'direct_ip_host=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://{NEW_IP}/admin/login
curl -sv -H 'Host: {DOMAIN}' https://{NEW_IP}/admin/login -o /dev/null 2>&1 | tail -40
# proxy error log
tail -n 30 /var/log/nginx/error.log
""",
        )
    )
    old.close()

    new = connect(NEW)
    print("=== NEW firewall / nginx ===")
    print(
        sudo(
            new,
            NEW["password"],
            """
ufw status || true
ss -lptn 'sport = :443' | head
tail -n 20 /var/log/nginx/error.log
""",
        )
    )
    new.close()


if __name__ == "__main__":
    main()
