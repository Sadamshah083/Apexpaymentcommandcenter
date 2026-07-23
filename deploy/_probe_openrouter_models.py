#!/usr/bin/env python3
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

use Illuminate\Support\Facades\Http;

$key = config('openrouter.api_key');
$base = rtrim(config('openrouter.base_url'), '/');
$models = [
    'openai/gpt-oss-20b:free',
    'openrouter/free',
    'meta-llama/llama-3.3-70b-instruct:free',
    'google/gemma-3-27b-it:free',
    'qwen/qwen3-4b:free',
    'mistralai/mistral-small-3.1-24b-instruct:free',
    'deepseek/deepseek-chat-v3-0324:free',
    'google/gemini-flash-1.5',
    'google/gemini-2.5-flash-preview',
    'openai/gpt-4o-mini',
];
foreach ($models as $model) {
    $paid = Http::withToken($key)->timeout(25)->post($base.'/chat/completions', [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => 'Reply with OK only']],
        'max_tokens' => 8,
    ]);
    $msg = $paid->json('error.message') ?: substr($paid->body(), 0, 120);
    $content = $paid->json('choices.0.message.content');
    echo $model.' => '.$paid->status().' '.($content ? ('OK:'.substr((string)$content,0,40)) : substr((string)$msg,0,140)).PHP_EOL;
}
"""

ssh = connect()
try:
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_try_models.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, "php /tmp/apex_try_models.php", check=False))
finally:
    ssh.close()
