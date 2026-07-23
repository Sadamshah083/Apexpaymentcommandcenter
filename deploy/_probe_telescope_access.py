#!/usr/bin/env python3
"""Probe Telescope 403 cause on production."""
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

ssh = connect()
try:
    print(sudo_run(ssh, r"""
cd /var/www/apexone
echo '=== env ==='
grep -E '^TELESCOPE|^APP_ENV|^APP_DEBUG' .env || true
echo '=== provider ==='
ls -la app/Providers/*elescope* 2>/dev/null || echo no_provider
ls -la vendor/laravel/telescope/src/TelescopeServiceProvider.php 2>/dev/null | head -1
echo '=== gate ==='
grep -n "Gate\|viewTelescope\|TELESCOPE\|enabled" app/Providers/TelescopeServiceProvider.php 2>/dev/null || echo no_local_provider
find app -name '*elescope*' 2>/dev/null
echo '=== bootstrap ==='
grep -n telescope bootstrap/providers.php config/app.php 2>/dev/null || true
php artisan tinker --execute="
echo 'enabled='.(config('telescope.enabled') ? '1':'0').PHP_EOL;
echo 'path='.config('telescope.path').PHP_EOL;
echo 'env='.app()->environment().PHP_EOL;
"
""", check=False))
finally:
    ssh.close()
