#!/usr/bin/env python3
"""Print production Morpheus extension caller IDs for outbound DID setup."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

PHP = """<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$svc = app(App\\Services\\Integrations\\ZoomApiService::class);
$rows = $svc->listExtensions(["limit" => 50]);
$items = $rows["extensions"] ?? (is_array($rows) ? $rows : []);
$out = [];
foreach ($items as $e) {
    if (!is_array($e)) {
        continue;
    }
    $out[] = [
        "extension_num" => $e["extension_num"] ?? null,
        "caller_id_num" => $e["caller_id_num"] ?? ($e["outbound_cid_num"] ?? null),
        "caller_id_name" => $e["caller_id_name"] ?? ($e["outbound_cid_name"] ?? null),
    ];
}
echo json_encode($out);
"""


def main() -> int:
    ssh = connect()
    env = sudo_run(ssh, r'grep -E "^(MORPHEUS_|COMMUNICATIONS_)" /var/www/apexone/.env 2>/dev/null || true', check=False)
    print("=== ENV (secrets masked) ===")
    for line in env.splitlines():
        if not line.strip():
            continue
        if any(x in line for x in ("API_KEY", "SECRET", "PASSWORD")):
            print(line.split("=", 1)[0] + "=***")
        else:
            print(line)

    encoded = base64.b64encode(PHP.encode()).decode()
    raw = sudo_run(
        ssh,
        f"cd /var/www/apexone && echo {encoded} | base64 -d | sudo -u www-data php",
        check=False,
    )
    ssh.close()

    print("\n=== EXTENSIONS ===")
    print(raw.strip() or "(empty)")

    try:
        exts = json.loads(raw.strip())
        dids = sorted({e["caller_id_num"] for e in exts if e.get("caller_id_num")})
        if dids:
            print("\n=== SUGGESTED .env LINE ===")
            print(f"COMMUNICATIONS_DEFAULT_OUTBOUND_DID={dids[0]}")
        else:
            print("\n(No caller_id_num on extensions — set DID from Morpheus portal)")
    except json.JSONDecodeError:
        pass

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
