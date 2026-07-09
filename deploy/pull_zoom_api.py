#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect
ssh = connect()
sftp = ssh.open_sftp()
local = ROOT / "deploy" / "prod-ZoomApiService.php"
sftp.get(f"{REMOTE_APP}/app/Services/Integrations/ZoomApiService.php", str(local))
sftp.close()
ssh.close()
print(f"Downloaded to {local}")
