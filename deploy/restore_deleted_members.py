#!/usr/bin/env python3
"""Restore deleted workspace membership from sync payload + list other removals."""

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
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$ws = Workspace::where("name", "ApexPayments")->first() ?: Workspace::find(2);
if (! $ws) {
    fwrite(STDERR, "Workspace missing\n");
    exit(1);
}

echo "=== recent member.removed ===\n";
$removed = DB::table("workspace_sync_events")
    ->where("workspace_id", $ws->id)
    ->where("event_type", "member.removed")
    ->orderByDesc("id")
    ->limit(20)
    ->get();

foreach ($removed as $r) {
    echo "{$r->id}\t{$r->created_at}\tuser={$r->entity_id}\t{$r->payload}\n";
}

// Restore every removed membership that still has a user row and is not currently attached.
$restored = 0;
foreach ($removed as $r) {
    $userId = (int) $r->entity_id;
    $user = User::find($userId);
    if (! $user) {
        echo "SKIP missing user {$userId}\n";
        continue;
    }

    $already = $ws->users()->where("user_id", $userId)->exists();
    if ($already) {
        echo "SKIP already member {$user->email}\n";
        continue;
    }

    $payload = json_decode((string) $r->payload, true) ?: [];
    $role = (string) ($payload["role"] ?? "appointment_setter");
    $status = (string) ($payload["status"] ?? "active");
    if (! in_array($status, ["active", "suspended"], true)) {
        $status = "active";
    }

    $ws->users()->syncWithoutDetaching([
        $user->id => [
            "role" => $role,
            "status" => $status,
            "joined_at" => now(),
            "team_lead_user_id" => $payload["team_lead_user_id"] ?? null,
            "campaign_id" => $payload["campaign_id"] ?? null,
            "module_permissions" => isset($payload["module_permissions"])
                ? (is_string($payload["module_permissions"])
                    ? $payload["module_permissions"]
                    : json_encode($payload["module_permissions"]))
                : null,
        ],
    ]);

    if (! $user->current_workspace_id) {
        $user->update(["current_workspace_id" => $ws->id]);
    }

    // Keep known admin alias password usable
    if (str_contains(strtolower($user->email), "apexonepayment") && in_array($role, ["admin", "super_admin"], true)) {
        $user->password = "123456";
        $user->save();
    }

    echo "RESTORED {$user->id} {$user->email} role={$role} status={$status}\n";
    $restored++;
}

echo "RESTORED_COUNT={$restored}\n";

echo "\n=== ApexPayments members now ===\n";
foreach ($ws->users()->orderBy("users.id")->get() as $u) {
    echo "{$u->id}\t{$u->name}\t{$u->email}\t{$u->pivot->role}\t{$u->pivot->status}\n";
}
'''


def main() -> int:
    ssh = connect()
    local = ROOT / "deploy" / "_tmp_restore_members.php"
    local.write_text(SCRIPT, encoding="utf-8")
    upload_files(ssh, [(local, "scripts/_tmp_restore_members.php")])
    out = sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php scripts/_tmp_restore_members.php && rm -f scripts/_tmp_restore_members.php", check=False)
    print(out.encode("ascii", "replace").decode())
    local.unlink(missing_ok=True)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
