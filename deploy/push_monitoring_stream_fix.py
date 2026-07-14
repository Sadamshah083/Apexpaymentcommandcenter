#!/usr/bin/env python3
"""Deploy call-monitoring fixes: dedupe, SSE nginx, JS rebuild, verify."""
from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Http/Controllers/CallMonitoringController.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/MorpheusCallEventService.php",
    "resources/js/call-monitoring.js",
    "tests/Unit/Services/CallMonitoringServiceTest.php",
]

NGINX_PATCH = r'''
python3 <<'PY'
from pathlib import Path
path = Path("/etc/nginx/sites-enabled/apexone")
text = path.read_text()
old = """    location ~ \\.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120s;
        fastcgi_send_timeout 120s;
    }"""
new = """    # Long-lived SSE (call monitoring / workspace sync) needs no buffering + long timeouts.
    location ~ \\.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_buffering off;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
    }"""
if old not in text:
    # Already patched or layout changed — try softer match.
    if "fastcgi_buffering off" in text and "fastcgi_read_timeout 3600s" in text:
        print("NGINX: already patched")
    else:
        raise SystemExit("NGINX patch target not found")
else:
    path.write_text(text.replace(old, new, 1))
    print("NGINX: patched SSE timeouts")
PY
nginx -t && systemctl reload nginx
'''


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Patching nginx for SSE...")
    print(sudo_run(ssh, NGINX_PATCH, check=False))

    print("Building assets + clearing caches...")
    out = sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1; tail -n 20 /tmp/vite-build.log; echo BUILD:$?",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build {REMOTE_APP}/storage {REMOTE_APP}/bootstrap/cache",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        ],
        check=False,
    )
    print(out)

    verify = sudo_run(
        ssh,
        f"ls -lt {REMOTE_APP}/public/build/assets/call-monitoring*.js | head -2; "
        f"JS=$(ls -t {REMOTE_APP}/public/build/assets/call-monitoring*.js | head -1); "
        f"echo JS:$JS; "
        f"echo navOnly:$(grep -c navOnly \"$JS\"); "
        f"echo 30000:$(grep -c 30000 \"$JS\"); "
        f"grep -n 'fastcgi_buffering\\|fastcgi_read_timeout' /etc/nginx/sites-enabled/apexone; "
        f"cd {REMOTE_APP} && sudo -u www-data php artisan test --filter=CallMonitoringServiceTest 2>&1 | tail -n 40",
        check=False,
    )
    print(verify)
    ssh.close()
    print("Deploy complete. Hard-refresh Call Monitoring (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
