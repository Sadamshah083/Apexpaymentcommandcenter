#!/usr/bin/env python3
import shlex
import paramiko

HOST = "203.215.160.44"
USER = "issac"
PW = "SadamShah123"
DOMAIN = "crm.apexonepayments.com"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=30)

cmd = f"""
python3 - <<'PY'
from pathlib import Path
path = Path('/etc/nginx/sites-available/apexone-proxy')
text = path.read_text()
marker = 'location /communications-ws/'
if marker in text:
    print('OLD_PROXY_WS_ALREADY')
else:
    block = '''
    location /communications-ws/ {{
        proxy_pass http://apexone_new;
        proxy_http_version 1.1;
        proxy_buffering off;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host {DOMAIN};
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host {DOMAIN};
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }}
'''
    needle = '    location / {{'
    if needle not in text:
        raise SystemExit('missing location /')
    path.write_text(text.replace(needle, block + '\\n' + needle, 1))
    print('OLD_PROXY_WS_ADDED')
PY
nginx -t
systemctl reload nginx
echo PROXY_DONE
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
chan = ssh.get_transport().open_session()
chan.settimeout(60)
chan.exec_command(full)
out = b""
while True:
    if chan.recv_ready():
        out += chan.recv(65535)
    if chan.recv_stderr_ready():
        out += chan.recv_stderr(65535)
    if chan.exit_status_ready():
        while chan.recv_ready():
            out += chan.recv(65535)
        while chan.recv_stderr_ready():
            out += chan.recv_stderr(65535)
        break
print(out.decode(errors="replace"))
print("exit", chan.recv_exit_status())
ssh.close()
