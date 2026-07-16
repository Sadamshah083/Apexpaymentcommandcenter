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
    "t=Path('/var/www/apexone/public/build/assets/communications-hub-Do_qhYf-.css').read_text(errors='replace')\n"
    "print('max-height:980px', 'max-height:980px' in t or 'max-height: 980px' in t)\n"
    "print('980px count', t.count('980px'))\n"
    "print('860px count', t.count('860px'))\n"
    "print('overflow-y:hidden keypad', t.count('overflow-y:hidden') + t.count('overflow-y: hidden'))\n"
    "src=Path('/var/www/apexone/resources/css/comm-hub-ghl-theme.css').read_text()\n"
    "print('src_980', '@media (max-height: 980px)' in src)\n"
    "PY"
)
print(out.read().decode())
ssh.close()
