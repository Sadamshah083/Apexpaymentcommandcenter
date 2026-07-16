#!/usr/bin/env python3
import shlex
import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PW = "balitech1"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=30)
cmd = r"""
cd /var/www/apexone
python3 - <<'PY'
from pathlib import Path
import json
m=json.loads(Path('public/build/manifest.json').read_text())
for k,v in m.items():
    if not str(v.get('file','')).endswith('.js'):
        continue
    p=Path('public/build')/v['file']
    if not p.exists():
        continue
    t=p.read_text(errors='replace')
    hits=[]
    if 'comm:dial-started' in t: hits.append('dial-started')
    if 'No recent calls yet.' in t and 'call logs scroll failed' in t: hits.append('call-logs-hydrate')
    if 'Soft reopen for dialing' in t: hits.append('soft-reopen') # only PHP
    if 'Force mic open after accept' in t or 'startMicHealthWatch' in t and 'session.accept' in t: hits.append('mic-answer')
    if hits:
        print(k, '->', v['file'], hits)
print('circuit_open=', end='')
PY
sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(App\Services\Integrations\MorpheusCircuitBreaker::class)->isOpen() ? "open" : "closed";
echo "\n";
'
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=60)
print((o.read()+e.read()).decode(errors='replace'))
ssh.close()
