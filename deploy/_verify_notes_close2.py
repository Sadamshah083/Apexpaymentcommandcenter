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
    "needles=['closeNotesPanel','Auto-saved','active notes save failed','__apexActiveCallNotes','is-notes-open']\n"
    "for p in sorted(root.glob('*.js')):\n"
    "    t=p.read_text(errors='replace')\n"
    "    hits=[n for n in needles if n in t]\n"
    "    if hits:\n"
    "        print(p.name, hits)\n"
    "src=Path('/var/www/apexone/resources/js/communications-phone-notes.js').read_text()\n"
    "print('src_close', 'closeNotesPanel' in src)\n"
    "print('src_comment_input', 'active-comment-input' in src)\n"
    "PY"
)
print(out.read().decode())
ssh.close()
