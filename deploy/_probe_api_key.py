#!/usr/bin/env python3
"""Probe Morpheus API key presence on NEW (masked only)."""
from __future__ import annotations

import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"

INNER = r"""
set -e
cd /var/www/apexone
echo '=== .env keys (masked) ==='
grep -E '^MORPHEUS_(API_KEY|PLATFORM_API_KEY|HOST)=' .env 2>/dev/null | while IFS= read -r line; do
  key="${line%%=*}"
  val="${line#*=}"
  val="${val%\"}"
  val="${val#\"}"
  if [ -z "$val" ]; then
    echo "${key}=<blank>"
  else
    len=${#val}
    pref=$(printf '%s' "$val" | cut -c1-8)
    echo "${key}=${pref}… (len=${len})"
  fi
done
echo '=== laravel config (masked) ==='
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$k = trim((string) config("integrations.morpheus.api_key"));
$p = trim((string) config("integrations.morpheus.platform_api_key"));
$h = (string) config("integrations.morpheus.host");
echo "host=".$h.PHP_EOL;
echo "api_key_set=".($k !== "" ? "yes" : "no")." len=".strlen($k)." prefix=".substr($k, 0, 8).PHP_EOL;
echo "platform_api_key_set=".($p !== "" ? "yes" : "no")." len=".strlen($p)." prefix=".substr($p, 0, 8).PHP_EOL;
'
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=20)
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(INNER)}"
    _, stdout, stderr = ssh.exec_command(cmd, timeout=90)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    print(out)
    for line in err.splitlines():
        if "password" in line.lower():
            continue
        print(line)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
