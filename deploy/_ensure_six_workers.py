#!/usr/bin/env python3
from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect


def run(ssh, cmd: str) -> str:
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=60)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    # strip sudo password prompt noise
    lines = [ln for ln in (out + "\n" + err).splitlines() if "[sudo]" not in ln]
    return "\n".join(lines).strip()


def main() -> int:
    ssh = connect()
    print("=== current workers ===")
    print(run(ssh, "pgrep -af 'queue:(work|pool)' || echo NONE"))

    print("=== how queue is managed ===")
    print(
        run(
            ssh,
            """
systemctl list-units --all --type=service 2>/dev/null | grep -iE 'queue|apex|supervisor' || true
ls /etc/systemd/system/*queue* /etc/systemd/system/*apex* 2>/dev/null || true
crontab -l 2>/dev/null | grep -i queue || true
sudo crontab -l 2>/dev/null | grep -i queue || true
ls /etc/supervisor/conf.d/ 2>/dev/null || true
cat /etc/systemd/system/apexone-queue.service 2>/dev/null || cat /etc/systemd/system/apex-queue.service 2>/dev/null || true
""",
        )
    )

    print("=== ensure 6 workers via systemd or restart ===")
    print(
        run(
            ssh,
            f"""
cd {REMOTE_APP}
# Prefer systemd if present
if systemctl list-unit-files 2>/dev/null | grep -q 'apexone-queue'; then
  systemctl restart apexone-queue
  sleep 2
  systemctl status apexone-queue --no-pager -l | head -40
elif systemctl list-unit-files 2>/dev/null | grep -q 'apex-queue'; then
  systemctl restart apex-queue
  sleep 2
  systemctl status apex-queue --no-pager -l | head -40
else
  pkill -f 'artisan queue:(pool|work)' || true
  sleep 1
  # Start under www-data with explicit --workers=6
  cd {REMOTE_APP}
  sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=6 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'
  sleep 4
fi
echo ---
pgrep -af 'queue:(work|pool)' || echo NONE
wc -l storage/logs/queue-pool.log 2>/dev/null || true
tail -30 storage/logs/queue-pool.log 2>/dev/null || true
""",
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
