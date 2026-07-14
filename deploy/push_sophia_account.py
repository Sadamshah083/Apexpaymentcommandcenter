#!/usr/bin/env python3
"""Upsert Sophia Haider (and team accounts) on production; verify login hash."""

from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "scripts/upsert_team_accounts.php",
    "scripts/assign_team_extensions.php",
]

VERIFY_PHP = """<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$u = App\\Models\\User::query()
    ->where('email', 'sophia@apexonepayments.com')
    ->orWhere('name', 'like', '%Sophia%')
    ->first();
if (!$u) {
    echo \"NOT_FOUND\\n\";
    exit(1);
}
$ok = Illuminate\\Support\\Facades\\Hash::check('123456', $u->password) ? 'yes' : 'no';
echo \"id={$u->id} name={$u->name} email={$u->email} password_123456={$ok}\\n\";
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Upserting team accounts...")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/upsert_team_accounts.php"))

    print("Assigning extensions...")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/assign_team_extensions.php"))

    print("Verifying Sophia...")
    remote = f"{REMOTE_APP}/storage/app/_verify_sophia.php"
    b64 = base64.b64encode(VERIFY_PHP.encode()).decode()
    sudo_run(
        ssh,
        f"printf %s {shlex.quote(b64)} | base64 -d > {remote} "
        f"&& chown www-data:www-data {remote}",
    )
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_verify_sophia.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)

    ssh.close()
    print("Sophia account push complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
