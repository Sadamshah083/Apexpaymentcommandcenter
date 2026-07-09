#!/usr/bin/env python3
import io, sys, paramiko
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
cmds = [
    "sed -n '200,280p' /opt/voicebot/tts.py",
    "grep -n 'ELEVENLABS_VOICE_NAMES\\|agent_name' /opt/voicebot/config.py /opt/voicebot/voice_pool.py | head -30",
    "sed -n '1,80p' /opt/voicebot/voice_pool.py",
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('157.180.56.227', 2223, 'root', 'AWWZtvksWCWRw5', timeout=30, look_for_keys=False, allow_agent=False)
for cmd in cmds:
    print('\n===', cmd, '===')
    _, o, _ = c.exec_command(cmd, timeout=60)
    o.channel.settimeout(60)
    print(o.read().decode(errors='replace'))
c.close()
