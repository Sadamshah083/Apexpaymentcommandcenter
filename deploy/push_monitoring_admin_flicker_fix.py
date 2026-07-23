#!/usr/bin/env python3
"""Deploy Call Monitoring: hide admins + stop wallboard flicker."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/AgentPresenceService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "resources/js/call-monitoring.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing files:", missing)
        return 1

    ssh = connect()
    print(f"Uploading {len(pairs)} files to {m.HOST}...")
    upload_files(ssh, pairs, REMOTE_APP)

    print("Lint + build + clear caches...")
    try:
        out = sudo_run_batch(
            ssh,
            [
                f"php -l {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php",
                f"php -l {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php",
                f"php -l {REMOTE_APP}/app/Http/Controllers/CallMonitoringController.php",
                f"cd {REMOTE_APP} && npm run build",
                f"chown -R www-data:www-data {REMOTE_APP}/public/build",
                f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
                f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
            ],
        )
        print(out.encode("ascii", "replace").decode("ascii"))
    except Exception as exc:
        print(f"Build step error: {exc}")
        raise

    print("Verify markers...")
    print(
        sudo_run(
            ssh,
            f"""
grep -n "looksLikeAdminIdentity\\|Ban EVERY excluded member\\|structureKey" \\
  {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php \\
  {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php \\
  {REMOTE_APP}/resources/js/call-monitoring.js | head -40
""",
            check=False,
        ).encode("ascii", "replace").decode("ascii")
    )

    # Quick runtime check: admin must not appear in WS2 snapshot
    sftp = ssh.open_sftp()
    php = r'''
$ws = App\Models\Workspace::find(2);
$svc = app(App\Services\Communications\CallMonitoringService::class);
$snap = $svc->snapshot($ws, light: true);
$leaks = [];
foreach ($snap['tables'] as $bucket => $rows) {
  foreach ($rows as $row) {
    $name = strtolower((string)($row['user'] ?? ''));
    $role = strtolower((string)($row['role_label'] ?? ''));
    $uid = (int)($row['user_id'] ?? 0);
    if ($uid === 1 || $name === 'admin' || $role === 'admin' || $role === 'super admin'
        || str_contains($name, 'admin') && in_array($role, ['admin','super admin',''], true)) {
      $leaks[] = "$bucket|{$row['user']}|{$row['role_label']}|uid=$uid";
    }
  }
}
echo empty($leaks) ? "VERIFY_OK no admin rows\n" : ("VERIFY_FAIL ".implode('; ', $leaks)."\n");
echo "summary=".json_encode($snap['summary'])."\n";
'''
    with sftp.file("/tmp/verify_mon_admin.php", "w") as f:
        f.write("<?php\n" + php)
    sftp.close()
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data php -r \"require '{REMOTE_APP}/vendor/autoload.php'; \\$app=require '{REMOTE_APP}/bootstrap/app.php'; \\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); require '/tmp/verify_mon_admin.php';\"",
            check=False,
        )
    )

    ssh.close()
    print("Deployed. Hard-refresh Call Monitoring (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
