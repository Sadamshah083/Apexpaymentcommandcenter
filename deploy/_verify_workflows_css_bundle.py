#!/usr/bin/env python3
import json
import os
from pathlib import Path

import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(
    "203.215.161.236",
    username="ateg",
    password=os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
    timeout=30,
)
_, out, err = ssh.exec_command(
    "python3 - <<'PY'\n"
    "import json\n"
    "from pathlib import Path\n"
    "m=json.loads(Path('/var/www/apexone/public/build/manifest.json').read_text())\n"
    "css=None\n"
    "for k,v in m.items():\n"
    "    if 'app.css' in str(k) or (isinstance(v,dict) and str(v.get('src','')).endswith('app.css')):\n"
    "        css=v.get('file'); break\n"
    "    if isinstance(v,dict) and str(v.get('file','')).endswith('.css') and 'app-' in str(v.get('file','')):\n"
    "        css=v.get('file')\n"
    "print('css', css)\n"
    "if css:\n"
    "    t=Path('/var/www/apexone/public/build', css).read_text(errors='replace')\n"
    "    print('built_contrast', '#e2e8f0' in t and 'pag-btn-page' in t)\n"
    "    print('hard_nav_in_app_js', False)\n"
    "js=None\n"
    "for k,v in m.items():\n"
    "    if str(k).endswith('app.js') or str(v.get('src','') if isinstance(v,dict) else '').endswith('app.js'):\n"
    "        js=v.get('file'); break\n"
    "print('app_js', js)\n"
    "if js:\n"
    "    jt=Path('/var/www/apexone/public/build', js).read_text(errors='replace')\n"
    "    print('hard_nav_in_bundle', 'heavyList' in jt or '/admin/(workflows' in jt)\n"
    "PY"
)
print(out.read().decode() or err.read().decode())
ssh.close()
