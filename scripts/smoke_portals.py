#!/usr/bin/env python3
"""Smoke-test critical portal and admin routes on production (HTTP status only)."""

from __future__ import annotations

import sys
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

BASE = "http://203.215.160.44"

# Unauthenticated should redirect to login (302/303), not 500.
ROUTES = [
    "/up",
    "/portal/login",
    "/admin/login",
    "/portal/communications",
    "/admin/communications",
    "/portal/setter",
    "/admin/dashboard",
    "/admin/workflows",
]


def check(path: str) -> tuple[int, str]:
    url = f"{BASE}{path}"
    try:
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=15) as resp:
            return resp.status, "OK"
    except urllib.error.HTTPError as e:
        return e.code, "HTTP error"
    except Exception as e:
        return 0, str(e)[:80]


def main() -> int:
    failed = []
    print(f"Smoke test {BASE}\n")
    for path in ROUTES:
        code, note = check(path)
        ok = code in {200, 302, 303, 401, 403}
        status = "PASS" if ok else "FAIL"
        print(f"  [{status}] {code:>3} {path} ({note})")
        if not ok:
            failed.append(path)

    print()
    if failed:
        print(f"FAILED: {len(failed)} route(s)")
        return 1
    print("All routes healthy (no 500s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
