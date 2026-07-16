#!/usr/bin/env python3
"""Fix admin emails/passwords and verify agent-status deploy on production."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
REMOTE_APP = "/var/www/apexone"

ARTISAN_SCRIPT = r"""<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$hash = Illuminate\Support\Facades\Hash::make('123456');

$targets = [
    [
        'find' => ['admin_ops_74b@apexpayments.com', 'admin@apexonepayment.com'],
        'names' => ['admin_ops_74b', 'admin'],
        'email' => 'admin@apexonepayment.com',
        'name' => 'admin',
        'label' => 'OPS',
    ],
    [
        'find' => ['admin_super_91a@apexpayments.com', 'superadmin@apexonepayment.com'],
        'names' => ['admin_super_91a', 'superadmin'],
        'email' => 'superadmin@apexonepayment.com',
        'name' => 'superadmin',
        'label' => 'SUP',
    ],
];

foreach ($targets as $t) {
    $user = App\Models\User::query()
        ->where(function ($q) use ($t) {
            $q->whereIn('email', $t['find'])->orWhereIn('name', $t['names']);
        })
        ->first();
    if (! $user) {
        echo $t['label'] . ":missing\n";
        continue;
    }
    $user->forceFill([
        'email' => $t['email'],
        'name' => $t['name'],
        'password' => $hash,
    ])->save();
    echo $t['label'] . ':' . $user->id . ':' . $user->email . "\n";
}

echo "NOTICE:" . (config('deployment.notice_enabled') ? 'on' : 'off') . "\n";
echo "ROUTE:" . (app('router')->has('admin.communications.agent-status') ? 'ok' : 'missing') . "\n";
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=30)

    sftp = ssh.open_sftp()
    tmp_script = "/tmp/apex_fix_admin_emails.php"
    with sftp.file(tmp_script, "w") as fh:
        fh.write(ARTISAN_SCRIPT)
    sftp.close()

    cmd = f"""
set -e
cp {tmp_script} {REMOTE_APP}/_fix_admin_emails.php
chown www-data:www-data {REMOTE_APP}/_fix_admin_emails.php
cd {REMOTE_APP}
sudo -u www-data php _fix_admin_emails.php
rm -f _fix_admin_emails.php {tmp_script}
sudo -u www-data php artisan route:list --path=agent-status
test -f resources/views/communications/agent-status/partials/panel.blade.php && echo PANEL_OK
grep -n "Team Lead Status" resources/views/layouts/partials/sidebar-nav-admin.blade.php | head -2
grep -n "DEPLOYMENT_NOTICE_ENABLED" .env | head -2
test -f config/deployment.php && echo CONFIG_OK
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=120)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
