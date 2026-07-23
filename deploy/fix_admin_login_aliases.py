#!/usr/bin/env python3
"""Ensure admin login aliases + password 123456 on NEW domain DB."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"

from deploy._ssh import connect, sudo_run, upload_files

SCRIPT = r'''<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require __DIR__ . "/../bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$password = "123456";

// Canonical admin accounts (ensure password)
$canonical = [
    "admin@apexonepayment.com",
    "superadmin@apexonepayment.com",
    "admin_ops_74b@apexpayments.com",
    "sherazbali@apexpayments.local",
];

foreach ($canonical as $email) {
    $u = User::query()->whereRaw("LOWER(email) = ?", [strtolower($email)])->first();
    if (! $u) {
        echo "MISSING {$email}\n";
        continue;
    }
    $u->password = $password;
    $u->save();
    echo "RESET {$email}\n";
}

// Alias: plural domain email -> same person as singular if missing
$aliases = [
    "admin@apexonepayments.com" => "admin@apexonepayment.com",
    "superadmin@apexonepayments.com" => "superadmin@apexonepayment.com",
];

foreach ($aliases as $aliasEmail => $canonicalEmail) {
    $canonicalUser = User::query()->whereRaw("LOWER(email) = ?", [strtolower($canonicalEmail)])->first();
    if (! $canonicalUser) {
        echo "SKIP alias {$aliasEmail} (canonical missing)\n";
        continue;
    }

    $existing = User::query()->whereRaw("LOWER(email) = ?", [strtolower($aliasEmail)])->first();
    if ($existing) {
        $existing->password = $password;
        $existing->current_workspace_id = $canonicalUser->current_workspace_id;
        $existing->save();
        // Ensure admin membership copied
        foreach ($canonicalUser->workspaces as $ws) {
            $existing->workspaces()->syncWithoutDetaching([
                $ws->id => [
                    "role" => $ws->pivot->role,
                    "status" => $ws->pivot->status ?? "active",
                    "joined_at" => $ws->pivot->joined_at ?? now(),
                ],
            ]);
        }
        echo "ALIAS_UPDATED {$aliasEmail}\n";
        continue;
    }

    $alias = $canonicalUser->replicate(["email"]);
    $alias->email = $aliasEmail;
    $alias->name = $canonicalUser->name . " (alias)";
    $alias->password = $password;
    $alias->current_workspace_id = $canonicalUser->current_workspace_id;
    $alias->save();

    foreach ($canonicalUser->workspaces as $ws) {
        $alias->workspaces()->syncWithoutDetaching([
            $ws->id => [
                "role" => $ws->pivot->role,
                "status" => $ws->pivot->status ?? "active",
                "joined_at" => $ws->pivot->joined_at ?? now(),
            ],
        ]);
    }
    echo "ALIAS_CREATED {$aliasEmail}\n";
}

// Verify
foreach (array_merge($canonical, array_keys($aliases)) as $email) {
    $u = User::query()->whereRaw("LOWER(email) = ?", [strtolower($email)])->first();
    if (! $u) {
        echo "VERIFY_FAIL missing {$email}\n";
        continue;
    }
    $ok = Hash::check($password, $u->password) ? "YES" : "NO";
    echo "VERIFY {$email} password_ok={$ok} admin=" . ($u->isAdminOfAnyWorkspace() ? "YES" : "NO") . "\n";
}
'''


def main() -> int:
    ssh = connect()
    local = ROOT / "deploy" / "_tmp_alias_admins.php"
    local.write_text(SCRIPT, encoding="utf-8")
    upload_files(ssh, [(local, "scripts/_tmp_alias_admins.php")])
    print(sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php scripts/_tmp_alias_admins.php && rm -f scripts/_tmp_alias_admins.php"))

    # Final HTTP smoke both singular and plural
    print(sudo_run(ssh, r'''
smoke() {
  email="$1"; label="$2"
  jar=/tmp/a_$label.jar
  rm -f $jar /tmp/a_$label.html /tmp/a_${label}_hdr.txt
  curl -sk -c $jar -o /tmp/a_$label.html https://127.0.0.1/admin/login -H "Host: crm.apexonepayments.com" >/dev/null
  token=$(python3 -c "import re; h=open('/tmp/a_$label.html','rb').read().decode('utf-8','replace'); m=re.search(r'name=\"_token\"\\s+value=\"([^\"]+)\"',h); print(m.group(1) if m else '')")
  code=$(curl -sk -b $jar -c $jar -D /tmp/a_${label}_hdr.txt -o /dev/null -w "%{http_code}" \
    -X POST https://127.0.0.1/admin/login -H "Host: crm.apexonepayments.com" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    --data-urlencode "_token=$token" --data-urlencode "email=$email" --data-urlencode "password=123456")
  loc=$(grep -i "^Location:" /tmp/a_${label}_hdr.txt | tr -d "\r" | head -1)
  echo "$label POST=$code $loc"
}
smoke "admin@apexonepayment.com" SINGULAR
smoke "admin@apexonepayments.com" PLURAL
smoke "superadmin@apexonepayment.com" SUPER
''', check=False))

    local.unlink(missing_ok=True)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
