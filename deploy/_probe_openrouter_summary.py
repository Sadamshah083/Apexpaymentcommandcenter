#!/usr/bin/env python3
"""Diagnose OpenRouter call-summary model failures."""

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

echo 'key_set='.(filled(config('openrouter.api_key')) ? 'yes' : 'no')."\n";
echo 'primary='.config('openrouter.call_summary_model')."\n";
echo 'fallback='.json_encode(config('openrouter.call_summary_fallback_models'))."\n";

$client = app(App\Services\BusinessResearch\OpenRouterClient::class);
try {
    $r = $client->chatForCallSummary(
        'You write one short US-English call summary paragraph. Return only the paragraph.',
        "Facts:\nAgent: tonnynewman\nTo: +19013851222\nDisposition: Corporate Business\nDuration: 57 seconds\nNotes: CORPORATE\n\nWrite the call summary paragraph now.",
        180,
    );
    echo "ok model={$r['model']}\n";
    echo "content={$r['content']}\n";
} catch (Throwable $e) {
    echo 'ERR='.$e->getMessage()."\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_or_probe.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_or_probe.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
