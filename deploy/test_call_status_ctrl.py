#!/usr/bin/env python3
import base64, sys
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
$user = App\\Models\\User::first();
Illuminate\\Support\\Facades\\Auth::login($user);
$req = Illuminate\\Http\\Request::create('/calls/'.$uuid, 'GET', ['destination' => '+12722001232']);
$ctrl = app(App\\Http\\Controllers\\MorpheusHubController::class);
try {{
    $resp = $ctrl->callStatus($req, $uuid);
    echo $resp->getContent();
}} catch (Throwable $e) {{
    echo json_encode(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}}
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php", check=False))
ssh.close()
