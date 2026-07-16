#!/usr/bin/env python3
import os
import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(
    "203.215.161.236",
    username="ateg",
    password=os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
    timeout=30,
)
_, out, _ = ssh.exec_command(
    "python3 - <<'PY'\n"
    "from pathlib import Path\n"
    "import json\n"
    "m=json.loads(Path('/var/www/apexone/public/build/manifest.json').read_text())\n"
    "for key in ['resources/js/communications-webphone.js','resources/js/app.js']:\n"
    "    pass\n"
    "files=[]\n"
    "for k,v in m.items():\n"
    "    f=str(v.get('file',''))\n"
    "    if 'communications' in f and f.endswith('.js') and 'auto-dial' not in f:\n"
    "        files.append(f)\n"
    "    if 'communications-auto-dial' in f:\n"
    "        files.append(f)\n"
    "for f in files:\n"
    "    t=Path('/var/www/apexone/public/build', f).read_text(errors='replace')\n"
    "    print(f)\n"
    "    print('  remote_clear_live=', 'remote-hangup' in t)\n"
    "    print('  allow_remote_dispo=', 'remoteHangupHandled' in t)\n"
    "PY"
)
print(out.read().decode())
ssh.close()
