#!/usr/bin/env python3
import io, sys, paramiko
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
cmds = [
    "sed -n '120,420p' /opt/voicebot/dialogue.py",
    "grep -n 'precache\\|opening_greeting\\|greeting\\|MOOD\\|PITCH\\|MED' /opt/voicebot/main.py /opt/voicebot/dialogue.py /opt/voicebot/tts.py 2>/dev/null | head -40",
    "sed -n '1,120p' /opt/voicebot/tts.py",
    "grep -n 'greeting\\|opening' /opt/voicebot/main.py | head -20",
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('157.180.56.227', 2223, 'root', 'AWWZtvksWCWRw5', timeout=30, look_for_keys=False, allow_agent=False)
for cmd in cmds:
    print('\n===', cmd[:80], '===')
    _, o, _ = c.exec_command(cmd, timeout=60)
    o.channel.settimeout(60)
    print(o.read().decode(errors='replace')[:8000])
c.close()
