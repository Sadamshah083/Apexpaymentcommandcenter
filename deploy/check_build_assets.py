#!/usr/bin/env python3
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

ssh = connect()
print("=== server files ===")
print(sudo_run(ssh, "ls -la /var/www/apexone/public/build/assets/communications-* 2>&1", check=False))

manifest_raw = sudo_run(ssh, "cat /var/www/apexone/public/build/manifest.json", check=True)
manifest = json.loads(manifest_raw)
for key, val in manifest.items():
    if "communications" in key:
        file = val.get("file", "")
        print(f"manifest: {key} -> {file}")
        _, o, _ = ssh.exec_command(f"curl -sI https://crm.apexonepayments.com/build/{file} | head -1")
        print(f"  /build/{file}: {o.read().decode().strip()}")

ssh.close()
