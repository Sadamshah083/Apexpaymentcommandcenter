#!/usr/bin/env python3
"""Inspect all Morpheus CDR legs for a call UUID."""
from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = sys.argv[1] if len(sys.argv) > 1 else "92ca371c-4239-48df-a64d-04e88ef5509b"

PHP = rf"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$uuid = '{UUID}';
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$ref = new ReflectionClass($zoom);
$find = $ref->getMethod('findCdrLegsByUuid');
$find->setAccessible(true);
$legs = $find->invoke($zoom, $uuid);

echo json_encode([
    'uuid' => $uuid,
    'legs' => $legs,
    'quick_get' => $ref->getMethod('quickGetCall')->invoke($zoom, $uuid),
    'dest_answered' => $zoom->destinationAnsweredOnCall($uuid, '+12722001232'),
    'resolve' => $zoom->resolveCallSnapshot($uuid),
    'hub_status' => $zoom->hubCallStatus($uuid, '+12722001232', false),
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
