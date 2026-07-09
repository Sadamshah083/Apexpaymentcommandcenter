#!/usr/bin/env python3
"""Hang up zombie Morpheus calls blocking extension."""
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
echo "Before: " . json_encode($api->listCalls()) . PHP_EOL;
$released = $api->releaseStaleActiveCalls(0);
echo "Released: " . json_encode($released) . PHP_EOL;
echo "After: " . json_encode($api->listCalls()) . PHP_EOL;
"""

ssh = connect()
tmp = "/tmp/release-calls.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
