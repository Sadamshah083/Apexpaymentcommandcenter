#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / "deploy/_probe_monitoring_health.php", "storage/app/_probe_monitoring_health.php")])
    out = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_probe_monitoring_health.php; "
        f"rm -f storage/app/_probe_monitoring_health.php",
        check=False,
    )
    print(out[:16000])

    ngx = sudo_run(
        ssh,
        "grep -Rni 'proxy_buffering\\|fastcgi_buffering\\|X-Accel\\|event-stream\\|proxy_read_timeout' "
        "/etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null | head -50",
        check=False,
    )
    print("---NGINX---")
    print(ngx[:5000])

    # quick stream smoke with cookie-less expect redirect/login — just confirm route responds
    code = sudo_run(
        ssh,
        "curl -s -o /tmp/mon_stream_head.txt -w '%{http_code}' "
        f"-H 'Accept: text/event-stream' --max-time 3 "
        f"http://127.0.0.1/admin/communications/monitoring/stream || true; "
        "echo; head -c 400 /tmp/mon_stream_head.txt; echo",
        check=False,
    )
    print("---STREAM_LOCAL---")
    print(code[:2000])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
