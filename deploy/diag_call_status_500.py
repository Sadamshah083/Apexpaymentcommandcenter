#!/usr/bin/env python3
"""Reproduce call status 500 for a Morpheus UUID."""
from __future__ import annotations
import base64
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = sys.argv[1] if len(sys.argv) > 1 else "392f6c1c-e368-4ea8-b324-45369958c92f"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

$uuid = {UUID!r};
$api = app(App\\Services\\Integrations\\ZoomApiService::class);

try {{
    $snap = $api->getCall($uuid);
    echo "getCall: " . json_encode($snap, JSON_PRETTY_PRINT) . "\\n";
    $dest = $api->destinationAnsweredOnCall($uuid, '+12722001232');
    echo "destinationAnswered: " . json_encode($dest) . "\\n";
}} catch (Throwable $e) {{
    echo "EXCEPTION: " . $e->getMessage() . "\\n" . $e->getTraceAsString() . "\\n";
}}
"""

def main() -> int:
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php", check=False))
    print(sudo_run(ssh, f"tail -40 {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | tail -25", check=False))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
