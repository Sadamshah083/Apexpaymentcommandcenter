#!/usr/bin/env python3
from __future__ import annotations
import io, sys
import paramiko

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

SCRIPT = b"""#!/bin/bash
set -e
# Disable unused Medicare AGI
if [ -f /opt/voicebot/medicarebot_agi.py ]; then
  mv /opt/voicebot/medicarebot_agi.py /opt/voicebot/medicarebot_agi.py.disabled
  echo 'disabled medicarebot_agi.py'
fi
# Fix 3rd SIP peer per integration sheet
sed -i 's/host=94.130.207.51/host=46.4.95.36/' /etc/asterisk/sip.conf
grep 'Testpeers_old3' -A2 /etc/asterisk/sip.conf | grep host=
asterisk -rx 'sip reload'
echo '=== SIP PEERS ==='
grep 'host=' /etc/asterisk/sip.conf | grep -v ';'
echo '=== EXTENSIONS ==='
grep -E 'AGENT_API|NON_AGENT|TRANSFER_EXT' /etc/asterisk/extensions.conf | head -3
echo '=== ENV ==='
grep VICIDIAL /opt/voicebot/.env; grep BOT_SERVER /opt/voicebot/.env
test -f /opt/voicebot/medicarebot_agi.py && echo WARN || echo 'medicarebot_agi disabled OK'
grep -rniE '37\\.27\\.133|66666|77777|25004|medicarebot|user=15001' /opt/voicebot/*.py /etc/asterisk/ 2>/dev/null | grep -v disabled | grep -v 'not Medicare' || echo 'no medicare refs OK'
cd /opt/voicebot && source venv/bin/activate && set -a && source .env && set +a
python3 -c "from vicidial_api import API_AUTH, fetch_logged_in_agents; from config import VICIDIAL_TRANSFER_EXT; print('API', API_AUTH); print('XFER', VICIDIAL_TRANSFER_EXT); r=fetch_logged_in_agents(force=True); print('agents', [x[0]+'/'+x[1]+'/'+x[2] for x in r[:5]])"
systemctl is-active voicebot voicebot-bridge asterisk
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('157.180.56.227', 2223, 'root', 'AWWZtvksWCWRw5', timeout=30, look_for_keys=False, allow_agent=False)
stdin, stdout, stderr = c.exec_command('bash -s', timeout=120)
stdin.write(SCRIPT); stdin.channel.shutdown_write()
print(stdout.read().decode(errors='replace'))
c.close()
