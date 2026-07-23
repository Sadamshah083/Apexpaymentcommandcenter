#!/usr/bin/env python3
"""Check OpenRouter auth/credits after possible payment."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = (string) config('openrouter.api_key');
echo "or_prefix=" . substr($key, 0, 12) . " suffix=" . substr($key, -4) . "\n";
$res = Illuminate\Support\Facades\Http::timeout(20)
    ->withHeaders(['Authorization' => 'Bearer ' . $key])
    ->get('https://openrouter.ai/api/v1/auth/key');
echo "auth_status=" . $res->status() . "\n";
echo "auth_body=" . substr(preg_replace('/\s+/', ' ', $res->body()), 0, 400) . "\n";

// Tiny paid/free chat test
$chat = Illuminate\Support\Facades\Http::timeout(45)
    ->withHeaders([
        'Authorization' => 'Bearer ' . $key,
        'Content-Type' => 'application/json',
        'HTTP-Referer' => config('app.url'),
        'X-Title' => 'ApexOne',
    ])
    ->post('https://openrouter.ai/api/v1/chat/completions', [
        'model' => 'openrouter/free',
        'messages' => [['role'=>'user','content'=>'Say OK']],
        'max_tokens' => 8,
    ]);
echo "chat_free_status=" . $chat->status() . " => " . substr(preg_replace('/\s+/',' ', $chat->body()), 0, 220) . "\n";
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_or.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_or.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
