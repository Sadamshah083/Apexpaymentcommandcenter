#!/usr/bin/env python3
"""Reset all user passwords/hints to 123456 and fix @apexpayments emails."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

PHP = r'''<?php
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$updated = 0;
$emailFixed = 0;
$hintOnly = 0;

foreach (User::query()->orderBy("id")->cursor() as $user) {
    $dirty = false;

    $needsPassword = ! Hash::check("123456", (string) $user->password);
    $needsHint = (string) ($user->password_hint ?? "") !== "123456";
    if ($needsPassword || $needsHint) {
        if ($needsPassword) {
            $user->password = "123456";
            $updated++;
        } else {
            $hintOnly++;
        }
        $user->password_hint = "123456";
        $dirty = true;
    }

    $email = strtolower((string) $user->email);
    if (str_ends_with($email, "@apexpayments.com")) {
        $local = strstr($email, "@", true) ?: preg_replace("/\s+/", "", strtolower((string) $user->name));
        $local = preg_replace("/[^a-z0-9._+-]/", "", (string) $local) ?: ("agent" . $user->id);
        $candidate = $local . "@apexonepayments.com";
        if (! User::query()->where("email", $candidate)->where("id", "!=", $user->id)->exists()) {
            $user->email = $candidate;
            $emailFixed++;
            $dirty = true;
        }
    }

    if ($dirty) {
        $user->save();
    }
}

echo "passwords_reset={$updated} hints_synced={$hintOnly} emails_fixed={$emailFixed}\n";
$sample = User::query()->orderBy("id")->limit(8)->get(["id", "name", "email", "password_hint"]);
foreach ($sample as $u) {
    echo $u->id . "|" . $u->name . "|" . $u->email . "|" . ($u->password_hint ?: "-") . "\n";
}
'''


def main() -> int:
    local = ROOT / "deploy" / "_reset_passwords_123456.php"
    local.write_text(PHP, encoding="utf-8")
    ssh = connect()
    upload_files(ssh, [(local, "deploy/_reset_passwords_123456.php"), (ROOT / "resources/views/workflows/workspaces.blade.php", "resources/views/workflows/workspaces.blade.php")], app_root=REMOTE_APP)
    print(sudo_run(ssh, f"""
cd {REMOTE_APP}
sudo -u www-data php deploy/_reset_passwords_123456.php
sudo -u www-data php artisan view:clear
""", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
