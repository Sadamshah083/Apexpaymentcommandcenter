#!/usr/bin/env python3
"""Diagnose CACHE_DRIVER / sqlite cache table and fix if needed."""
import os
from pathlib import Path

import paramiko

HOST = "203.215.160.44"
USER = "issac"
ROOT = "/var/www/apexone"


def password():
    p = Path(__file__).with_name(".deploy_password")
    if p.exists():
        return p.read_text(encoding="utf-8").strip()
    return os.environ.get("DEPLOY_PASSWORD", "")


def run(c, cmd, timeout=60):
    stdin, stdout, stderr = c.exec_command(cmd, timeout=timeout)
    return stdout.read().decode("utf-8", errors="replace"), stderr.read().decode(
        "utf-8", errors="replace"
    )


def main():
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(HOST, username=USER, password=password(), timeout=25)

    cmds = [
        f"grep -E '^CACHE_|^DB_CONNECTION|^SESSION_' {ROOT}/.env | sed 's/=.*/=***/' ",
        f"grep -E '^CACHE_|^DB_CONNECTION|^SESSION_' {ROOT}/.env",
        f"ls -la {ROOT}/database/database.sqlite 2>/dev/null; file {ROOT}/database/database.sqlite 2>/dev/null",
        f"sqlite3 {ROOT}/database/database.sqlite '.tables' 2>&1 | head -20",
        f"cd {ROOT} && php artisan cache:table 2>&1 | tail -20",
        f"cd {ROOT} && php -r \"echo config('cache.default').PHP_EOL;\" 2>&1",
        f"cd {ROOT} && php artisan tinker --execute=\"echo config('cache.default');\" 2>&1 | tail -5",
    ]
    for cmd in cmds:
        print("====", cmd[:90])
        out, err = run(c, cmd)
        print(out[-2000:] or "(empty)")
        if err.strip():
            print("ERR", err[-500:])

    # Proper config dump via bootstrap
    php = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'cache.default='.config('cache.default').PHP_EOL;
echo 'cache.stores.database='.json_encode(config('cache.stores.database')).PHP_EOL;
echo 'db.default='.config('database.default').PHP_EOL;
try {
  Illuminate\Support\Facades\Cache::put('probe_cache_ok', 1, 10);
  echo 'cache_put=ok get='.Illuminate\Support\Facades\Cache::get('probe_cache_ok').PHP_EOL;
} catch (Throwable $e) {
  echo 'cache_put_fail='.$e->getMessage().PHP_EOL;
}
"""
    sftp = c.open_sftp()
    with sftp.file("/tmp/_cache_diag.php", "w") as f:
        f.write(php)
    sftp.close()
    out, err = run(c, f"cd {ROOT} && php /tmp/_cache_diag.php")
    print("DIAG:\n", out, err[-500:])
    c.close()


if __name__ == "__main__":
    main()
