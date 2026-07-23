#!/usr/bin/env python3
"""Try multiple Gemini models to see if any accept generation after top-up."""

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

$key = (string) config('gemini.api_key');
$base = rtrim(config('gemini.base_url'), '/');
$models = ['gemini-2.5-flash','gemini-2.0-flash','gemini-2.5-pro','gemini-flash-latest','gemini-2.0-flash-lite'];

foreach ($models as $model) {
    $res = Illuminate\Support\Facades\Http::timeout(30)
        ->withHeaders(['x-goog-api-key' => $key, 'Content-Type' => 'application/json'])
        ->post($base . '/models/' . $model . ':generateContent', [
            'contents' => [['parts' => [['text' => 'Reply with OK']]]],
            'generationConfig' => ['maxOutputTokens' => 8, 'temperature' => 0],
        ]);
    $msg = $res->json('error.message') ?? substr(preg_replace('/\s+/',' ', $res->body()), 0, 120);
    echo $model . " status=" . $res->status() . " => " . $msg . "\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_gemmodels.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_gemmodels.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
