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
    "jt=Path('/var/www/apexone/public/build/assets/app-Cn66svZg.js').read_text(errors='replace')\n"
    "print('len', len(jt))\n"
    "print('assign', jt.count('location.assign'))\n"
    "print('workflows_re', '/admin/(workflows' in jt or 'admin/workflows' in jt)\n"
    "print('paginationPreserve', 'paginationPreserve' in jt or 'data-pagination' in jt)\n"
    "src=Path('/var/www/apexone/resources/js/pagination-preserve.js').read_text(errors='replace')\n"
    "print('src_heavy', 'heavyList' in src)\n"
    "PY"
)
print(out.read().decode())
ssh.close()
