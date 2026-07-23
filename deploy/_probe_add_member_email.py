#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import REMOTE_APP, connect, sudo_run
ssh = connect()
out = sudo_run(ssh, r"""
echo '=== add-member modal fields ==='
grep -n 'create-email\|create-username\|name="email"\|name="username"' /var/www/apexone/resources/views/workflows/partials/add-member-modal.blade.php | head -40
echo '=== controller email validate ==='
grep -n email /var/www/apexone/app/Http/Controllers/WorkspaceMemberController.php | head -20
echo '=== createAgent signature ==='
grep -n 'function createAgent\|email' /var/www/apexone/app/Services/Workspace/WorkspaceMemberService.php | head -25
""", check=False)
sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
sys.stdout.buffer.write(b"\n")
ssh.close()
