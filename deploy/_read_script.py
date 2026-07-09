#!/usr/bin/env python3
import io, sys, paramiko
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
cmds = [
    "grep -rn 'SYSTEM_PROMPT\\|ACTIVE_SYSTEM_PROMPT\\|Senior Ease\\|medicine\\|TRANSFER\\|stage\\|cache' /opt/voicebot/config.py /opt/voicebot/dialogue.py /opt/voicebot/main.py 2>/dev/null | head -60",
    "sed -n '120,220p' /opt/voicebot/config.py",
    "sed -n '1,120p' /opt/voicebot/dialogue.py",
    "grep -rn 'medications\\|8\\|TRANSFER\\|stage\\|router' /opt/voicebot/dialogue.py /opt/voicebot/config.py 2>/dev/null | head -40",
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('157.180.56.227', 2223, 'root', 'AWWZtvksWCWRw5', timeout=30, look_for_keys=False, allow_agent=False)
for cmd in cmds:
    print('\n===', cmd[:80], '===')
    _, o, _ = c.exec_command(cmd, timeout=60)
    o.channel.settimeout(60)
    print(o.read().decode(errors='replace')[:6000])
c.close()
