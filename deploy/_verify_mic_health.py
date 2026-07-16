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
    "p=Path('/var/www/apexone/public/build/assets/communications-CRjXedeN.js')\n"
    "t=p.read_text(errors='replace')\n"
    "print('silent_retune', t.count('Outbound mic silent'))\n"
    "print('soft_reopen', t.count('soft reopen'))\n"
    "print('no_constraint_retune', t.count('no constraint retune'))\n"
    "PY"
)
print(out.read().decode())
ssh.close()
