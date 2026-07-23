#!/usr/bin/env python3
"""Check laravel log for recent member create failures + form quirks on NEW."""
from __future__ import annotations

import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    inner = r"""
cd /var/www/apexone
echo '=== recent member/store errors ==='
sudo -u www-data grep -iE 'WorkspaceMember|members\.store|johnwilliam|ValidationException|createAgent|This email' storage/logs/laravel.log 2>/dev/null | tail -n 40 || true
echo '=== form password fields ==='
grep -n 'password\|team_lead\|create-email\|confirmed' resources/views/workflows/partials/add-member-modal.blade.php | head -40
echo '=== respond method ==='
grep -n 'function respond\|wantsJson\|Accept' app/Http/Controllers/WorkspaceMemberController.php | head -20
echo '=== pagination ==='
grep -n members_per_page config/pagination.php || true
php -r 'echo "ok\n";'
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=60)
    print((o.read() + e.read()).decode(errors="replace")[-10000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
