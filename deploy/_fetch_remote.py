#!/usr/bin/env python3
import io, sys, paramiko
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('157.180.56.227', 2223, 'root', 'AWWZtvksWCWRw5', timeout=30, look_for_keys=False, allow_agent=False)
sftp = c.open_sftp()
for path in ['/opt/voicebot/dialogue.py', '/opt/voicebot/config.py', '/opt/voicebot/tts.py']:
    local = path.split('/')[-1]
    sftp.get(path, f'D:/Email Checker/Email Checker/deploy/_remote_{local}')
    print('downloaded', local)
sftp.close()
c.close()
