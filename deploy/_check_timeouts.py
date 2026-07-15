#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
_pw = Path(__file__).with_name(".deploy_password")
if _pw.exists() and not os.environ.get("DEPLOY_PASSWORD"):
    os.environ["DEPLOY_PASSWORD"] = _pw.read_text(encoding="utf-8").strip()
import deploy._ssh as m
m.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or m.PASSWORD
from deploy._ssh import connect, sudo_run

print(sudo_run(connect(), r"""
sudo -u www-data grep -E '^COMMUNICATIONS_HTTP_TIMEOUT|^MORPHEUS_' /var/www/apexone/.env | sed 's/\(KEY\|PASSWORD\|SECRET\|TOKEN\)=.*/\1=***/'
sed -n '4250,4280p' /var/www/apexone/app/Services/Integrations/ZoomApiService.php
# latency smoke
for i in 1 2 3; do
  curl -s -o /dev/null -w "try$i connect=%{time_connect} ttfb=%{time_starttransfer} total=%{time_total} code=%{http_code}\n" \
    --connect-timeout 5 --max-time 10 \
    -H "Authorization: Bearer dummy" \
    https://apexone.morpheus.cx/api/v1/call-control/users?limit=1 || echo fail$i
done
""", check=False))
