#!/usr/bin/env python3
"""Verify calling hotfix is live on production."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()

    checks = [
        ("originate-json", "grep -c data-originate-json /var/www/apexone/resources/views/communications/partials/dialer-form.blade.php"),
        ("hangup-url", "grep -c data-hangup-url /var/www/apexone/resources/views/communications/partials/webphone-panel.blade.php"),
        ("click-to-call-ringing", "grep -c showClickToCallRinging /var/www/apexone/resources/js/communications-webphone.js"),
        ("floating-popup", "test -f /var/www/apexone/resources/views/communications/partials/webphone-floating-popup.blade.php && echo 1"),
    ]

    print("Code markers:")
    for name, cmd in checks:
        _, o, _ = ssh.exec_command(cmd)
        print(f"  {name}: {o.read().decode().strip()}")

    manifest_raw = sudo_run(
        ssh,
        "cat /var/www/apexone/public/build/manifest.json",
        check=True,
    )
    manifest = json.loads(manifest_raw)
    for key in sorted(manifest):
        if "communications-dialer" in key or "communications-webphone" in key:
            file = manifest[key].get("file", "")
            print(f"  manifest {key} -> {file}")

    for file in [
        "build/assets/communications-dialer-wr37e-WK.js",
        "build/assets/communications-webphone-Dk4SJ0Bn.js",
        "build/manifest.json",
    ]:
        _, o, _ = ssh.exec_command(f"curl -fsSI https://crm.apexonepayments.com/{file} | head -1")
        print(f"  https://.../{file}: {o.read().decode().strip()}")

    ssh.close()
    print("Verification complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
