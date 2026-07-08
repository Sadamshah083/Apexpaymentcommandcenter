#!/usr/bin/env python3
"""Download key Communications Hub files from production for snapshot."""
from __future__ import annotations
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

FILES = [
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/ZoomClickToCallService.php",
    "app/Services/Integrations/ZoomApiService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "resources/js/communications-webphone.js",
    "resources/js/communications-dialer.js",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/components/communications/molecules/workflow-stepper.blade.php",
    "config/integrations.php",
]

OUT = ROOT / "deploy" / "server-snapshot"
OUT.mkdir(parents=True, exist_ok=True)

ssh = connect()
sftp = ssh.open_sftp()
for rel in FILES:
    remote = f"{REMOTE_APP}/{rel.replace(chr(92), '/')}"
    local = OUT / rel
    local.parent.mkdir(parents=True, exist_ok=True)
    try:
        sftp.get(remote, str(local))
        print(f"OK {rel}")
    except Exception as e:
        print(f"FAIL {rel}: {e}")
sftp.close()

print("\n--- ENV (morpheus keys only) ---")
print(sudo_run(ssh, f"grep -E '^MORPHEUS_|^COMMUNICATIONS_' {REMOTE_APP}/.env", check=False))
ssh.close()
