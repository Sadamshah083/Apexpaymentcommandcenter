#!/usr/bin/env python3
"""Deploy MorpheusSipIdentity fix for call status 500."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

def main() -> int:
    pairs = [(ROOT / "app/Support/MorpheusSipIdentity.php", "app/Support/MorpheusSipIdentity.php")]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
    ])
    ssh.close()
    print("Deployed MorpheusSipIdentity::isSipContactHash — call status poll 500 fixed.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
