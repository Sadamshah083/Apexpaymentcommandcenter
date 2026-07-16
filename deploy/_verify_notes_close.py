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
    "root=Path('/var/www/apexone/public/build/assets')\n"
    "for p in root.glob('*.js'):\n"
    "    t=p.read_text(errors='replace')\n"
    "    if 'closeNotesPanel' in t or 'Auto-saves to call log' in t or 'active_call_notes' in t:\n"
    "        print(p.name, 'close=', 'closeNotesPanel' in t, 'comment_ui=', 'Comment for this call' in t)\n"
    "blade=Path('/var/www/apexone/resources/views/communications/partials/dialer-form.blade.php').read_text()\n"
    "print('blade_comment', 'dialer-active-comment' in blade)\n"
    "print('blade_notes', 'data-dialer-active-notes-input' in blade)\n"
    "PY"
)
print(out.read().decode())
ssh.close()
