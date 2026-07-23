#!/usr/bin/env python3
from __future__ import annotations

import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PASSWORD = "balitech1"


def main() -> None:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=40)

    # Write a remote script and run via www-data if possible
    script = r"""
cd /var/www/apexone
python3 - <<'PY'
from pathlib import Path
env = Path('.env')
try:
    text = env.read_text(encoding='utf-8', errors='replace')
except Exception as e:
    print('ENV_READ_FAIL', e)
    text = ''
for line in text.splitlines():
    if line.startswith('SESSION_') or line.startswith('MORPHEUS_') and ('TIMEOUT' in line or 'CAMPAIGN' in line or 'DIAL' in line):
        print(line)
print('---')
# bootstrap config lifetime if readable as www-data later
PY
grep -a "SESSION_\|MORPHEUS_DIAL\|MORPHEUS_ORIGIN" .env 2>/dev/null | head -30 || true
"""
    # Use SFTP to read .env if permitted via sudo cat
    cmds = [
        "cat /var/www/apexone/.env 2>/dev/null | grep -E '^SESSION_|^MORPHEUS_DIAL|^MORPHEUS_ORIGIN|^MORPHEUS_DEFAULT' | head -40",
        "echo balitech1 | sudo -S grep -E '^SESSION_|^MORPHEUS_DIAL|^MORPHEUS_ORIGIN|^MORPHEUS_DEFAULT' /var/www/apexone/.env 2>/dev/null | head -40",
        "echo balitech1 | sudo -S php /var/www/apexone/artisan tinker --execute=\"echo config('session.lifetime');\" 2>/dev/null | tail -5",
        "echo balitech1 | sudo -S tail -c 800000 /var/www/apexone/storage/logs/laravel.log 2>/dev/null | tr -cd '\\11\\12\\15\\40-\\176\\n' | grep -iE 'Morpheus originate|extension_busy|TokenMismatch|Page Expired|CSRF|Could not place|webphone_required|extension_offline' | tail -n 80",
    ]
    for cmd in cmds:
        print("====", cmd[:110])
        stdin, stdout, stderr = ssh.exec_command(cmd, timeout=90)
        out = (stdout.read() + stderr.read()).decode("utf-8", "replace")
        print(out.encode("ascii", "replace").decode("ascii")[:4500])

    ssh.close()


if __name__ == "__main__":
    main()
