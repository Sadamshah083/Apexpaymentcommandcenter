#!/usr/bin/env python3
"""Inspect Gemini key/project on prod (masked) and compare probe endpoints."""

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
echo "key_prefix=" . substr($key, 0, 10) . "\n";
echo "key_suffix=" . substr($key, -6) . "\n";
echo "key_len=" . strlen($key) . "\n";
echo "base_url=" . config('gemini.base_url') . "\n";
echo "model=" . config('workflow_enrichment.gemini_model') . "\n";
echo "project=" . env('GEMINI_PROJECT_NUMBER') . "\n";
echo "env_key_prefix=" . substr((string) env('GEMINI_API_KEY'), 0, 10) . "\n";
echo "env_key_suffix=" . substr((string) env('GEMINI_API_KEY'), -6) . "\n";

// List models endpoint can also reveal billing/auth state
try {
    $request = Illuminate\Support\Facades\Http::timeout(20)
        ->withHeaders(['x-goog-api-key' => $key]);
    $url = rtrim(config('gemini.base_url'), '/') . '/models?pageSize=5';
    $res = $request->get($url);
    echo "list_models_status=" . $res->status() . "\n";
    echo "list_models_body=" . substr(preg_replace('/\s+/', ' ', $res->body()), 0, 280) . "\n";
} catch (Throwable $e) {
    echo "list_models_error=" . $e->getMessage() . "\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_gemkey.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_gemkey.php", check=False))
        print("---env gemini lines---")
        print(sudo_run(ssh, "grep -E '^GEMINI_|^WORKFLOW_GEMINI_' /var/www/apexone/.env | sed -E 's/(KEY=).*/\\1***/; s/(SECRET=).*/\\1***/'", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
