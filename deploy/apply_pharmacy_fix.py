#!/usr/bin/env python3
from __future__ import annotations

import io
import sys

import paramiko

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

HOST, PORT, USER, PASS = "157.180.56.227", 2223, "root", "AWWZtvksWCWRw5"

SCRIPT = b"""#!/bin/bash
set -e
ENV=/opt/voicebot/.env
set_kv() {
  k="$1"; v="$2"
  if grep -q "^${k}=" "$ENV"; then
    sed -i "s|^${k}=.*|${k}=${v}|" "$ENV"
  else
    echo "${k}=${v}" >> "$ENV"
  fi
}
set_kv VICIDIAL_BRIDGE_AGENT_PREFIX 160
set_kv VICIDIAL_DEFAULT_AGENT 16001

DISPO=/opt/voicebot/set_vicidial_dispo.py
sed -i 's/return DEFAULT_VICI_USER or "15001"/return DEFAULT_VICI_USER or os.getenv("VICIDIAL_DEFAULT_AGENT", "16001")/' "$DISPO"
sed -i 's/timeout=6,/timeout=12,/' /opt/voicebot/connect_qualified_call.py

echo '--- .env ---'
grep -E 'VICIDIAL_BRIDGE|VICIDIAL_DEFAULT|VICIDIAL_TRANSFER|VICIDIAL_API' "$ENV"
echo '--- dispo fallback ---'
grep -n 'return DEFAULT_VICI' "$DISPO"
echo '--- max duration ---'
grep 'MAX_CALL_DURATION_SEC =' /opt/voicebot/main.py | head -1

systemctl restart voicebot voicebot-bridge
sleep 2
systemctl is-active voicebot voicebot-bridge asterisk
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
print(f"Connecting {USER}@{HOST}:{PORT}")
c.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30, look_for_keys=False, allow_agent=False)

stdin, stdout, stderr = c.exec_command("bash -s", timeout=120)
stdin.write(SCRIPT)
stdin.channel.shutdown_write()
out = stdout.read().decode(errors="replace")
err = stderr.read().decode(errors="replace")
code = stdout.channel.recv_exit_status()
print(out)
if err.strip():
    print("STDERR:", err)
print("exit:", code)
c.close()
