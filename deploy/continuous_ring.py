#!/usr/bin/env python3
"""Continuous ring loop — fresh SSH per attempt, avoids connection stalls."""
from __future__ import annotations

import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXTS = ["1007", "1004", "1008", "1001", "1020"]
PAUSE = 18


def ts() -> str:
    return datetime.now(timezone.utc).strftime("%H:%M:%S UTC")


def main() -> int:
    n = 0
    print(f"[{ts()}] Continuous ring loop ON — destination +12722001232")
    print(f"Extensions: {', '.join(EXTS)} | pause={PAUSE}s between attempts")
    print("Say STOP or tell me when your cell rings.\n")
    sys.stdout.flush()
    try:
        while True:
            for ext in EXTS:
                n += 1
                print(f"\n>>> [{ts()}] ATTEMPT #{n} — click-to-call ext {ext} -> YOUR CELL <<<")
                sys.stdout.flush()
                r = subprocess.run(
                    [sys.executable, str(ROOT / "deploy" / "quick_ring.py"), ext],
                    cwd=str(ROOT),
                    capture_output=True,
                    text=True,
                    timeout=120,
                )
                out = (r.stdout or "") + (r.stderr or "")
                print(out.strip() or f"(no output, exit {r.returncode})")
                sys.stdout.flush()
                if "live=true" in out.lower() and "INCOMPATIBLE" not in out:
                    print(f"*** [{ts()}] CALL WAS LIVE on ext {ext} — check your phone ***")
                if "bill=" in out and any(f"bill={i}" in out for i in range(1, 60)):
                    print(f"*** [{ts()}] BILLSEC > 0 on ext {ext} — call connected ***")
                time.sleep(PAUSE)
    except KeyboardInterrupt:
        print(f"\n[{ts()}] Stopped after {n} attempts.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
