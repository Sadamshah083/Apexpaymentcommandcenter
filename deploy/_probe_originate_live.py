#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
print(
    sudo_run(
        ssh,
        r"""
cd /var/www/apexone
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$ext = "1020";
$opts = $agents->extensionDialOptions($ext);
echo "dial_opts=".json_encode($opts)."\n";
echo "endpoint_online=".json_encode($agents->extensionEndpointOnline($ext))."\n";

$result = $api->originateCall($ext, "+12722001232", array_merge($opts, [
    "webphone_ready" => true,
    "skip_line_clear" => true,
]));
echo "originate=".json_encode($result)."\n";
$formatted = $api->formatOriginateResponse($result, $ext, "+12722001232", $opts);
echo "formatted=".json_encode($formatted)."\n";
echo "formatted_len=".strlen(json_encode($formatted))."\n";
'
""",
        check=False,
    )
)
ssh.close()
