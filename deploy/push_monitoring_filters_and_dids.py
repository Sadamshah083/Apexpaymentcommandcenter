#!/usr/bin/env python3
"""Deploy monitoring role filters + assign DIDs to new appointment setters."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/AgentPresenceService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "scripts/assign_appointment_setter_dids.php",
]

VERIFY = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\AgentPresenceService;
use App\Services\Communications\CallMonitoringService;
use App\Support\SalesOps;

echo 'TL_excluded='.(AgentPresenceService::isExcludedFromMonitoring('appointment_setter_team_lead') ? 'yes':'no').PHP_EOL;
echo 'closer_TL_excluded='.(AgentPresenceService::isExcludedFromMonitoring('closers_team_lead') ? 'yes':'no').PHP_EOL;
echo 'setter_monitorable='.(AgentPresenceService::isMonitorableRole('appointment_setter') ? 'yes':'no').PHP_EOL;
echo 'closer_monitorable='.(AgentPresenceService::isMonitorableRole('closer') ? 'yes':'no').PHP_EOL;
echo 'TL_monitorable='.(AgentPresenceService::isMonitorableRole('appointment_setter_team_lead') ? 'yes':'no').PHP_EOL;

$ws = Workspace::where('name','ApexPayments')->firstOrFail();
$jacob = User::whereRaw('LOWER(email)=?',['jacob@apexonepayments.com'])->first();
$damon = User::whereRaw('LOWER(email)=?',['damonpeterson@apexonepayments.com'])->first();
$admin = User::whereRaw('LOWER(email)=?',['superadmin@apexonepayment.com'])->first();

echo 'filter_jacob='.json_encode(AgentPresenceService::monitorRolesForViewer($jacob,(int)$ws->id)).PHP_EOL;
echo 'filter_damon='.json_encode(AgentPresenceService::monitorRolesForViewer($damon,(int)$ws->id)).PHP_EOL;
echo 'filter_admin='.json_encode(AgentPresenceService::monitorRolesForViewer($admin,(int)$ws->id)).PHP_EOL;

auth()->login($jacob);
$snap = app(CallMonitoringService::class)->snapshot($ws, light: true);
$rows = array_merge($snap['active'] ?? [], $snap['waiting'] ?? [], $snap['idle'] ?? [], $snap['offline'] ?? [], $snap['not_logged_in'] ?? [], $snap['disposition'] ?? [], $snap['break'] ?? [], $snap['lunch'] ?? []);
// Prefer buckets if named differently
if ($rows === [] && isset($snap['rows'])) { $rows = $snap['rows']; }
$roles = [];
foreach (['active_calls','not_in_call','not_logged_in','disposition','break_lunch','rows','boards'] as $k) {
  if (!isset($snap[$k])) continue;
}
// Walk common wallboard structure
$collect = [];
$walk = function($node) use (&$walk, &$collect) {
  if (!is_array($node)) return;
  if (isset($node['role']) || isset($node['user']) || isset($node['station'])) {
    $collect[] = $node;
  }
  foreach ($node as $v) { if (is_array($v)) $walk($v); }
};
$walk($snap);
$bad = [];
foreach ($collect as $r) {
  $role = (string)($r['role'] ?? '');
  $name = (string)($r['user'] ?? $r['name'] ?? '');
  $label = strtolower((string)($r['role_label'] ?? ''));
  if ($role !== '' && (str_contains($role,'team_lead') || str_contains($label,'team lead'))) {
    $bad[] = "TL:$name/$role";
  }
  if ($role === 'closer' || $role === 'closers') {
    $bad[] = "CLOSER_ON_SETTER_BOARD:$name";
  }
}
echo 'jacob_board_bad='.( $bad ? implode(';',$bad) : 'none').' count_rows='.count($collect).PHP_EOL;
auth()->logout();
"""


def main() -> int:
    (ROOT / "deploy/_verify_monitoring_filters.php").write_text(VERIFY, encoding="utf-8")
    ssh = connect()
    try:
        upload_files(
            ssh,
            [(ROOT / rel, rel) for rel in FILES]
            + [(ROOT / "deploy/_verify_monitoring_filters.php", "scripts/_verify_monitoring_filters.php")],
            app_root=REMOTE_APP,
        )
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php scripts/assign_appointment_setter_dids.php",
            f"cd {REMOTE_APP} && sudo -u www-data php scripts/_verify_monitoring_filters.php",
            f"rm -f {REMOTE_APP}/scripts/_verify_monitoring_filters.php",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()
        p = ROOT / "deploy/_verify_monitoring_filters.php"
        if p.exists():
            p.unlink()


if __name__ == "__main__":
    raise SystemExit(main())
