#!/usr/bin/env python3
from deploy._ssh import REMOTE_APP, connect, sudo_run
ssh = connect()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list 2>&1 | grep dialer", check=False))
print(sudo_run(ssh, f"ls -la {REMOTE_APP}/bootstrap/cache/routes*.php 2>&1", check=False))
ssh.close()
