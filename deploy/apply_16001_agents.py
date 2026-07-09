#!/usr/bin/env python3
from __future__ import annotations

import io
import sys
from pathlib import Path

import paramiko

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

HOST, PORT, USER, PASS = "157.180.56.227", 2223, "root", "AWWZtvksWCWRw5"
PATCH = Path(__file__).parent / "remote_patch_16001.py"

VERIFY = b"""#!/bin/bash
cd /opt/voicebot && source venv/bin/activate && set -a && source .env && set +a
python3 - <<'PY'
from vicidial_api import fetch_logged_in_agents, API_AUTH
from set_vicidial_dispo import resolve_agent_user, _BRIDGE_AGENT_PREFIX
rows = fetch_logged_in_agents(force=True)
print('API_AUTH user', API_AUTH.get('user'))
print('bridge prefix', _BRIDGE_AGENT_PREFIX)
print('logged_in bridge agents', len(rows))
for r in rows[:8]:
    print(' ', r[0], r[1], r[2], r[3])
print('resolve empty ->', resolve_agent_user('', ''))
PY
grep 'user=16001' /etc/asterisk/extensions.conf | head -2
systemctl restart voicebot voicebot-bridge asterisk
sleep 2
systemctl is-active voicebot voicebot-bridge asterisk
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30, look_for_keys=False, allow_agent=False)

stdin, stdout, stderr = c.exec_command("cat > /tmp/remote_patch_16001.py")
stdin.write(PATCH.read_bytes())
stdin.channel.shutdown_write()
stdout.channel.recv_exit_status()

stdin, stdout, stderr = c.exec_command("python3 /tmp/remote_patch_16001.py", timeout=60)
stdout.channel.settimeout(60)
print(stdout.read().decode(errors="replace"))

stdin, stdout, stderr = c.exec_command("bash -s", timeout=90)
stdin.write(VERIFY)
stdin.channel.shutdown_write()
print(stdout.read().decode(errors="replace"))
c.close()
