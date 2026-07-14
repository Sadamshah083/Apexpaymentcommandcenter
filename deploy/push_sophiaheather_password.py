#!/usr/bin/env python3
"""Set only SophiaHeather password in production DB. Touches no other users."""

from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

# Only this account — nothing else.
LOGIN_NAME = "SophiaHeather"
PASSWORD = "Amisa123"
EMAIL = "sophiaheather@apexonepayments.com"
ROLE = "appointment_setter"

PHP = f"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Models\\User;
use App\\Models\\Workspace;
use Illuminate\\Support\\Facades\\Hash;

$workspace = Workspace::where('name', 'ApexPayments')->first();
if (!$workspace) {{
    fwrite(STDERR, "ApexPayments workspace not found\\n");
    exit(1);
}}

$login = {LOGIN_NAME!r};
$password = {PASSWORD!r};
$email = {EMAIL!r};
$role = {ROLE!r};

// Prefer existing Sophia account if present; otherwise create SophiaHeather only.
$user = User::query()
    ->where('name', $login)
    ->orWhere('name', 'Sophia Haider')
    ->orWhere('email', 'sophia@apexonepayments.com')
    ->orWhere('email', $email)
    ->orderByRaw("CASE WHEN name = ? THEN 0 ELSE 1 END", [$login])
    ->first();

if ($user) {{
    // Only rename login id + set password. Leave email/role alone.
    $user->forceFill([
        'name' => $login,
        'password' => $password,
    ])->save();
    $action = 'updated';
}} else {{
    $user = User::create([
        'name' => $login,
        'email' => $email,
        'password' => $password,
        'current_workspace_id' => $workspace->id,
    ]);
    $action = 'created';
}}

if (! $workspace->users()->where('user_id', $user->id)->exists()) {{
    $workspace->users()->attach($user->id, [
        'role' => $role,
        'status' => 'active',
        'joined_at' => now(),
    ]);
}}

$ok = Hash::check($password, $user->fresh()->password) ? 'yes' : 'no';
$pivotRole = $user->getWorkspaceRole($workspace->id) ?? 'n/a';
echo "{{$action}} id={{$user->id}} name={{$user->name}} email={{$user->email}} role={{$pivotRole}} password_ok={{$ok}}\\n";
"""


def main() -> int:
    ssh = connect()
    remote = f"{REMOTE_APP}/storage/app/_set_sophiaheather.php"
    b64 = base64.b64encode(PHP.encode()).decode()

    print("Writing one-shot password setter on server...")
    sudo_run(
        ssh,
        f"printf %s {shlex.quote(b64)} | base64 -d > {remote} "
        f"&& chown www-data:www-data {remote}",
    )

    print("Applying SophiaHeather password only...")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_set_sophiaheather.php"))

    sudo_run(ssh, f"rm -f {remote}", check=False)
    ssh.close()
    print("Done. No other accounts were changed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
