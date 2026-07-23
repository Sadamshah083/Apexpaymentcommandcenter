#!/usr/bin/env python3
"""Dump all monitoring rows for each workspace + presence map."""

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
foreach (App\Models\Workspace::orderBy('id')->get() as $ws) {
  echo "=== WS".$ws->id." ".$ws->name." ===".PHP_EOL;
  $svc = app(App\Services\Communications\CallMonitoringService::class);
  $snap = $svc->snapshot($ws, light: true);
  foreach (['not_in_call','not_logged_in','disposition','break','lunch','ringing','incall_short','incall_long','dead','queue'] as $bucket) {
    foreach (($snap['tables'][$bucket] ?? []) as $row) {
      echo "$bucket | ".($row['user'] ?? '')." | role=".($row['role_label'] ?? '')." | uid=".($row['user_id'] ?? '')." | st=".($row['station'] ?? '')." | id=".($row['id'] ?? '').PHP_EOL;
    }
  }
  $presence = app(App\Services\Communications\AgentPresenceService::class)->listOnline($ws);
  echo "presence=".count($presence).PHP_EOL;
  foreach ($presence as $p) {
    echo "  p uid=".($p['user_id']??'')." name=".($p['name']??'')." role=".($p['role']??'')." label=".($p['role_label']??'').PHP_EOL;
  }
  $roster = app(App\Services\Communications\CommunicationsAgentService::class)->listMonitorableDirectory($ws);
  echo "roster=".count($roster).PHP_EOL;
  foreach ($roster as $a) {
    $n = strtolower((string)($a['name']??''));
    $r = strtolower((string)($a['role']??''));
    if (str_contains($n,'admin') || in_array($r, ['admin','super_admin','manager'], true)) {
      echo "  ROSTER LEAK ".$a['name']." role=".$a['role']." uid=".$a['user_id'].PHP_EOL;
    }
  }
  // raw local directory for admin-like
  $local = app(App\Services\Communications\CommunicationsAgentService::class)->listLocalExtensionDirectory($ws);
  foreach ($local as $a) {
    $n = strtolower((string)($a['name']??''));
    $r = strtolower((string)($a['role']??''));
    $l = strtolower((string)($a['role_label']??''));
    if (str_contains($n,'admin') || in_array($r, ['admin','super_admin','manager'], true) || in_array($l, ['admin','super admin'], true)) {
      echo "  LOCAL ".$a['name']." role=".$a['role']." label=".$a['role_label']." ext=".($a['morpheus_extension_num']??'')." uid=".$a['user_id'].PHP_EOL;
      echo "    exclNoUser=".(App\Services\Communications\AgentPresenceService::isExcludedFromMonitoring($a['role']??'', $a['role_label']??'')?'1':'0').PHP_EOL;
      $u = App\Models\User::find($a['user_id']);
      if ($u) {
        echo "    exclWithUser=".(App\Services\Communications\AgentPresenceService::isExcludedFromMonitoring($a['role']??'', $a['role_label']??'', $u, (int)$ws->id)?'1':'0').PHP_EOL;
      }
    }
  }
}
'''


def main() -> int:
    ssh = connect()
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/probe_mon_rows.php", "w") as f:
        f.write("<?php\n" + PHP)
    sftp.close()
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data php -r \"require '{REMOTE_APP}/vendor/autoload.php'; \\$app=require '{REMOTE_APP}/bootstrap/app.php'; \\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); require '/tmp/probe_mon_rows.php';\"",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
