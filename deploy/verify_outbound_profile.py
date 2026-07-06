#!/usr/bin/env python3
"""Verify Morpheus outbound caller ID + campaign_id resolution on production."""

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
$agents = app(App\\Services\\Communications\\CommunicationsAgentService::class);
$zoom = app(App\\Services\\Integrations\\ZoomApiService::class);
echo json_encode([
    'profile' => $zoom->outboundCallingProfile(),
    'dial_options_1001' => $agents->extensionDialOptions('1001'),
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = connect()
    encoded = base64.b64encode(PHP.encode()).decode()
    raw = sudo_run(
        ssh,
        f"cd /var/www/apexone && echo {encoded} | base64 -d | sudo -u www-data php",
        check=False,
    )
    ssh.close()
    print(raw)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
