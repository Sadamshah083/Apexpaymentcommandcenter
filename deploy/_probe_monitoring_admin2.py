#!/usr/bin/env python3
"""List admin-like users and sample monitoring snapshot rows on prod."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r'''
$users = App\Models\User::query()
  ->where(function ($q) {
    $q->whereRaw('LOWER(name) = ?', ['admin'])
      ->orWhereRaw('LOWER(email) LIKE ?', ['admin%'])
      ->orWhereRaw('LOWER(email) LIKE ?', ['%admin%']);
  })
  ->limit(30)
  ->get(['id','name','email']);
echo "users=".count($users).PHP_EOL;
foreach ($users as $u) {
  echo $u->id." | ".$u->name." | ".$u->email.PHP_EOL;
  foreach ($u->workspaces as $w) {
    echo "  ws".$w->id." role=".($w->pivot->role ?? "")." status=".($w->pivot->status ?? "")." ext=".($w->pivot->morpheus_extension_num ?? "")." admin_id=".$w->admin_id.PHP_EOL;
    echo "  isAdmin=".($u->isAdmin($w->id)?'1':'0')." isSuperAdmin=".($u->isSuperAdmin($w->id)?'1':'0')." platformSA=".($u->isPlatformSuperAdmin()?'1':'0')." canAdmin=".($u->canAccessAdminPortal($w->id)?'1':'0').PHP_EOL;
    echo "  excluded=". (App\Services\Communications\AgentPresenceService::isExcludedFromMonitoring($w->pivot->role ?? '', App\Support\SalesOps::roleLabel($w->pivot->role ?? ''), $u, (int)$w->id) ? '1':'0').PHP_EOL;
  }
}
$ws = App\Models\Workspace::orderBy('id')->first();
if ($ws) {
  $svc = app(App\Services\Communications\CallMonitoringService::class);
  $snap = $svc->snapshot($ws, light: true);
  foreach (['not_in_call','not_logged_in','disposition','break','lunch','ringing','incall_short','incall_long','dead'] as $bucket) {
    foreach (($snap['tables'][$bucket] ?? []) as $row) {
      $name = strtolower((string)($row['user'] ?? ''));
      $role = strtolower((string)($row['role_label'] ?? ''));
      if (str_contains($name, 'admin') || $role === 'admin' || $role === 'super admin') {
        echo "ROW bucket=$bucket user=".($row['user'] ?? '')." role=".($row['role_label'] ?? '')." uid=".($row['user_id'] ?? '')." id=".($row['id'] ?? '').PHP_EOL;
      }
    }
  }
  echo "summary=".json_encode($snap['summary']).PHP_EOL;
}
'''


def main() -> int:
    ssh = connect()
    # Write PHP to temp file to avoid quoting hell
    script = f"cat > /tmp/probe_mon_admin.php <<'PHP'\n{PHP}\nPHP\ncd {REMOTE_APP} && sudo -u www-data php artisan tinker --execute=\"require '/tmp/probe_mon_admin.php';\""
    # Simpler: write via python upload
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/probe_mon_admin.php", "w") as f:
        f.write("<?php\n" + PHP)
    sftp.close()
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data php -r \"require '{REMOTE_APP}/vendor/autoload.php'; \\$app=require '{REMOTE_APP}/bootstrap/app.php'; \\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); require '/tmp/probe_mon_admin.php';\"",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
