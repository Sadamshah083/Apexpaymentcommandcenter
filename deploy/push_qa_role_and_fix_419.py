#!/usr/bin/env python3
"""Fix 419 login on old IP + deploy QA role / call-log ACL."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.160.44"
m.USER = "issac"
m.PASSWORD = "SadamShah123"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch, upload_files

FILES = [
    "config/sales_ops.php",
    "config/portal_modules.php",
    "app/Support/SalesOps.php",
    "app/Support/MemberModuleAccess.php",
    "app/Models/User.php",
    "app/Services/Communications/CommunicationsAccessService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/AgentPresenceService.php",
    "app/Http/Controllers/CallNotesController.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "scripts/upsert_team_accounts.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("1) Upload role/ACL files to OLD server...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("2) Fix session cookies for HTTP IP login (419 CSRF)...")
    # Empty SESSION_DOMAIN — literal \"null\" breaks cookies on IP hosts.
    set_env_vars(ssh, {
        "APP_URL": "http://203.215.160.44",
        "SESSION_DOMAIN": "",
        "SESSION_SECURE_COOKIE": "false",
        "SESSION_SAME_SITE": "lax",
        "SESSION_LIFETIME": "600",
    }, env_path=f"{REMOTE_APP}/.env")

    # Remove quoted empty / literal null domain leftovers
    sudo_run(ssh, f"""
python3 - <<'PY'
from pathlib import Path
p = Path('{REMOTE_APP}/.env')
lines = []
for line in p.read_text().splitlines():
    if line.startswith('SESSION_DOMAIN='):
        val = line.split('=', 1)[1].strip().strip('\"').strip(\"'\")
        if val.lower() in ('null', 'none', ''):
            continue  # omit → Laravel uses null domain
        lines.append(line)
    else:
        lines.append(line)
p.write_text('\\n'.join(lines) + '\\n')
print('SESSION_DOMAIN cleaned')
PY
""")

    print("3) Clear caches + upsert Hannah → B2B Closer Team QA...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/upsert_team_accounts.php | tail -n 30",
    ], check=False))

    print("4) Smoke: GET login + CSRF cookie + POST without token should 419; with flow check session cookie...")
    print(sudo_run(ssh, f"""
cd {REMOTE_APP}
grep -E '^(APP_URL|SESSION_)' .env | sed 's/PASSWORD=.*/PASSWORD=***/'
# Fetch login page and capture session cookie
rm -f /tmp/apex_login_cookies.txt
CODE=$(curl -s -c /tmp/apex_login_cookies.txt -o /tmp/apex_login.html -w '%{{http_code}}' http://127.0.0.1/admin/login -H 'Host: 203.215.160.44')
echo "GET_LOGIN=$CODE"
grep -o 'XSRF-TOKEN[^;]*' /tmp/apex_login_cookies.txt | head -1 || true
grep -o 'apexone[^[:space:]]*session[^[:space:]]*' /tmp/apex_login_cookies.txt | head -3 || true
TOKEN=$(python3 - <<'PY'
import re
html=open('/tmp/apex_login.html').read()
m=re.search(r'name=\"_token\"\\s+value=\"([^\"]+)\"', html)
print(m.group(1) if m else '')
PY
)
echo "TOKEN_LEN=${{#TOKEN}}"
# POST with token should not be 419 (may be 302 redirect or 422 validation)
POST=$(curl -s -b /tmp/apex_login_cookies.txt -c /tmp/apex_login_cookies.txt -o /tmp/apex_login_post.html -w '%{{http_code}}' \
  -X POST http://127.0.0.1/admin/login -H 'Host: 203.215.160.44' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode "_token=$TOKEN" \
  --data-urlencode "email=nosuchuser@example.com" \
  --data-urlencode "password=badpass")
echo "POST_LOGIN=$POST"
""", check=False))

    ssh.close()
    print("Done. Test http://203.215.160.44/admin/login in a fresh browser / incognito.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
