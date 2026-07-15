#!/usr/bin/env python3
"""Capture recent originate 422 bodies via temporary Laravel logging or access log correlation."""
import os
import re
from pathlib import Path

import paramiko

HOST = "203.215.160.44"
USER = "issac"
ROOT = "/var/www/apexone"


def password():
    p = Path(__file__).with_name(".deploy_password")
    if p.exists():
        return p.read_text(encoding="utf-8").strip()
    return os.environ.get("DEPLOY_PASSWORD", "")


def run(c, cmd, timeout=60):
    stdin, stdout, stderr = c.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return out, err


def main():
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(HOST, username=USER, password=password(), timeout=25)

    cmds = [
        "sudo grep -E 'calls/originate' /var/log/nginx/access.log | tail -30",
        "ls -lt /var/www/apexone/storage/logs/ | head -8",
        "sudo grep -E 'originate|ClickToCall|click.to.call|Unable to place|destination|extension_busy|webphone' /var/www/apexone/storage/logs/laravel.log 2>/dev/null | tail -50",
        "TODAY=$(date -u +%Y-%m-%d); sudo grep -E 'originate|422|ClickToCall' /var/www/apexone/storage/logs/laravel-$TODAY.log 2>/dev/null | tail -50",
        # Match response body sizes we saw
        "python3 - <<'PY'\n"
        "bodies={\n"
        "  'offline_connect_line': len('{\"ok\":false,\"error\":\"Connect line first — dialer is offline.\",\"webphone_required\":true,\"offline\":true}'),\n"
        "  'offline_short': len('{\"ok\":false,\"error\":\"Connect line first — dialer is offline.\",\"webphone_required\":true}'),\n"
        "  'connect_line_online': len('{\"ok\":false,\"error\":\"Connect your dialer line (top of Communications) before placing a call.\",\"webphone_required\":true}'),\n"
        "}\n"
        "for k,v in bodies.items():\n"
        "  print(k, v)\n"
        "PY",
    ]

    for cmd in cmds:
        print("=" * 60)
        print(cmd[:120])
        out, err = run(c, cmd)
        print(out[-5000:] if out else "(no out)")
        if err.strip():
            print("ERR:", err[-800:])

    # Read formatOriginateResponse / common errors from ZoomApiService on server
    out, _ = run(
        c,
        "grep -n 'formatOriginateResponse\\|extension_busy\\|Connect line\\|Unable to place\\|isValidPstn' "
        + ROOT
        + "/app/Services/Integrations/ZoomApiService.php | head -40",
    )
    print("SERVICE LINES:\n", out)

    c.close()


if __name__ == "__main__":
    main()
