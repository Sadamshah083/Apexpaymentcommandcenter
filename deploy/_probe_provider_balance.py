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

$credits = Http::withToken($key)->timeout(20)->get($base.'/credits');
echo 'credits_status='.$credits->status().PHP_EOL;
echo 'credits_body='.substr($credits->body(), 0, 500).PHP_EOL;

$key = config('gemini.api_key') ?: env('GEMINI_API_KEY') ?: env('GOOGLE_API_KEY');
$model = config('workflow_enrichment.gemini_model', 'gemini-2.5-flash');
$url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent?key='.$key;
$probe = Http::timeout(20)->post($url, [
    'contents' => [['parts' => [['text' => 'Reply with OK']]]],
    'generationConfig' => ['maxOutputTokens' => 8],
]);
echo 'gemini_status='.$probe->status().PHP_EOL;
echo 'gemini_body='.substr($probe->body(), 0, 400).PHP_EOL;

// Try a cheap paid OpenRouter model quickly
$paid = Http::withToken(config('openrouter.api_key'))->timeout(30)->post($base.'/chat/completions', [
    'model' => 'google/gemini-2.0-flash-001',
    'messages' => [
        ['role' => 'user', 'content' => 'Reply with OK only'],
    ],
    'max_tokens' => 8,
]);
echo 'paid_status='.$paid->status().PHP_EOL;
echo 'paid_body='.substr($paid->body(), 0, 400).PHP_EOL;
"""

ssh = connect()
try:
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_provider_balance.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, "php /tmp/apex_provider_balance.php", check=False))
finally:
    ssh.close()
