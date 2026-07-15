#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
cmd = r"""
# Capture latest 422 response body pattern by replaying common failure paths via artisan tinker-like php
cd /var/www/apexone
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = app(App\Services\Communications\CommunicationsAgentService::class);
echo "offline_msg=".substr($svc->extensionOfflineDialMessage("1020"),0,200)."\n";
echo "campaign=".config("integrations.morpheus.default_campaign_id")."\n";
echo "dial_method=".config("integrations.morpheus.dial_method")."\n";
'
# Check recent error response sizes matching nginx
python3 <<'PY'
sizes = {411: [], 473: []}
# Approximate which JSON messages match response sizes seen in nginx
candidates = [
    '{"ok":false,"error":"Enter a valid phone number with at least 10 digits (e.g. +12722001232)."}',
    '{"ok":false,"error":"Morpheus campaign_id is required for outbound calls. Set MORPHEUS_DEFAULT_CAMPAIGN_ID in .env or create an active campaign in Morpheus CX."}',
]
import json
from pathlib import Path
# Build realistic payloads with extension offline message
import subprocess
msg = subprocess.check_output(["php","-r",'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo app(App\\Services\\Communications\\CommunicationsAgentService::class)->extensionOfflineDialMessage("1020");'], text=True, cwd="/var/www/apexone")
payloads = [
    {"ok": False, "extension_offline": True, "webphone_required": True, "error": msg},
    {"ok": False, "extension_offline": True, "error": msg},
    {"ok": False, "error": "Enter a valid phone number with at least 10 digits (e.g. +12722001232)."},
    {"ok": False, "error": "Morpheus campaign_id is required for outbound calls. Set MORPHEUS_DEFAULT_CAMPAIGN_ID in .env or create an active campaign in Morpheus CX."},
]
for p in payloads:
    body = json.dumps(p, separators=(',', ':'))
    # Laravel json usually with spaces
    body2 = json.dumps(p)
    print(len(body), len(body2), p.get('error','')[:80].replace('\n',' '))
PY
"""
print(sudo_run(ssh, cmd, check=False))
ssh.close()
