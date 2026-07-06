#!/usr/bin/env python3
"""Copy MORPHEUS_EXTENSION_PASSWORD from production .env to local .env."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    line = sudo_run(ssh, f"grep -E '^MORPHEUS_EXTENSION_PASSWORD=' {REMOTE_APP}/.env")
    ssh.close()

    match = re.match(r"^MORPHEUS_EXTENSION_PASSWORD=(.*)$", line.strip())
    if not match:
        print("Remote password line missing.")
        return 1

    path = ROOT / ".env"
    text = path.read_text(encoding="utf-8")
    pattern = re.compile(r"^MORPHEUS_EXTENSION_PASSWORD=.*$", re.M)
    new_line = "MORPHEUS_EXTENSION_PASSWORD=" + match.group(1)
    text = pattern.sub(new_line, text) if pattern.search(text) else text.rstrip() + "\n" + new_line + "\n"
    path.write_text(text, encoding="utf-8")
    print("Local .env MORPHEUS_EXTENSION_PASSWORD synced from production.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
