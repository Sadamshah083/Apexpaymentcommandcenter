#!/usr/bin/env python3
"""Ensure local .env has JWT_SECRET + JWT_TTL_HOURS=9."""
from __future__ import annotations

import secrets
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
env_path = ROOT / ".env"
if not env_path.exists():
    print("NO_ENV")
    raise SystemExit(1)

text = env_path.read_text(encoding="utf-8")
lines = text.splitlines()
secret = secrets.token_urlsafe(48)
wanted = {"JWT_SECRET": secret, "JWT_TTL_HOURS": "9"}
changed = False

for key, value in wanted.items():
    found = False
    for i, line in enumerate(lines):
        if line.startswith(f"{key}="):
            if key == "JWT_TTL_HOURS" and line != f"{key}=9":
                lines[i] = f"{key}=9"
                changed = True
            elif key == "JWT_SECRET" and (line == f"{key}=" or not line.split("=", 1)[1].strip()):
                lines[i] = f"{key}={value}"
                changed = True
            found = True
            break
    if not found:
        lines.append(f"{key}={value}")
        changed = True

if changed:
    ending = "\n" if text.endswith("\n") else ""
    env_path.write_text("\n".join(lines) + ending, encoding="utf-8")
    print("ENV_JWT_UPDATED")
else:
    print("ENV_JWT_ALREADY_SET")

for line in env_path.read_text(encoding="utf-8").splitlines():
    if line.startswith("JWT_SECRET="):
        print("JWT_SECRET=(set)")
    elif line.startswith("JWT_"):
        print(line)
