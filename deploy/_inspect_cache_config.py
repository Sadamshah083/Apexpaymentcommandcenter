#!/usr/bin/env python3
"""Inspect cached Laravel config and whether www-data can use cache."""
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
        f"ls -la {ROOT}/.env {ROOT}/bootstrap/cache/config.php {ROOT}/bootstrap/cache/*.php 2>&1 | head -30",
        f"php -r \"$c=@file_get_contents('{ROOT}/bootstrap/cache/config.php'); if(!$c){{echo 'no config cache'; exit;}} "
        f"if(preg_match('/\\'default\\' => \\'([^\\']+)\\'/', $c, $m)){{}} "
        f"echo substr($c,0,200);\" 2>&1 | head",
        # Extract cache + db from config cache via grep
        f"grep -n \"'default'\" {ROOT}/bootstrap/cache/config.php | head -20",
        f"grep -n \"cache\\|sqlite\\|redis\\|mysql\\|database.sqlite\" {ROOT}/bootstrap/cache/config.php | head -60",
        # Run as www-data if possible
        f"sudo -n -u www-data php -r 'echo get_current_user();' 2>&1",
        f"sudo -n -u www-data bash -lc 'cd {ROOT} && php artisan tinker --execute=\"echo config(\\\"cache.default\\\").\\\"|\\\".config(\\\"database.default\\\");\"' 2>&1 | tail -20",
        # Try reading env via www-data
        f"sudo -n -u www-data bash -lc 'grep -E \"^(CACHE_|DB_CONNECTION|SESSION_DRIVER|REDIS_)\" {ROOT}/.env' 2>&1",
    ]
    for cmd in cmds:
        print("====", cmd[:100])
        out, err = run(c, cmd)
        print((out or "")[-3000:])
        if err.strip():
            print("ERR", err[-800:])

    # Parse config.php with a PHP script run as whatever user can read it
    php = r"""<?php
$path = '/var/www/apexone/bootstrap/cache/config.php';
if (!is_readable($path)) {
  echo "config cache not readable\n";
  exit(1);
}
$config = require $path;
echo 'cache.default='.($config['cache']['default'] ?? '?').PHP_EOL;
echo 'db.default='.($config['database']['default'] ?? '?').PHP_EOL;
echo 'cache.database.connection='.json_encode($config['cache']['stores']['database']['connection'] ?? null).PHP_EOL;
echo 'cache.database.table='.($config['cache']['stores']['database']['table'] ?? '?').PHP_EOL;
$db = $config['database']['default'] ?? 'sqlite';
echo 'db.conn='.json_encode($config['database']['connections'][$db] ?? null).PHP_EOL;
"""
    sftp = c.open_sftp()
    with sftp.file("/tmp/_cfg_read.php", "w") as f:
        f.write(php)
    sftp.close()
    out, err = run(c, "php /tmp/_cfg_read.php")
    print("CFG:\n", out, err)

    # Create cache tables on sqlite used by cli? Also check if mysql has cache
    # Better: switch CACHE to file via env if we can write .env as www-data / sudo

    out, err = run(
        c,
        "sudo -n true 2>&1; id; groups; ls -la /var/www/apexone/database/",
    )
    print("PRIVS:\n", out, err)
    c.close()


if __name__ == "__main__":
    main()
