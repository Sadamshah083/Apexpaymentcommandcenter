#!/usr/bin/env python3
"""Deploy JWT + error fixes to NEW production server only (203.215.161.236). No old-server fallback."""
from __future__ import annotations

import os
import secrets
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as ssh_mod
from deploy._ssh import connect, sudo_run, upload_files

NEW_HOST = "203.215.161.236"
NEW_USER = "ateg"
NEW_PASS = "balitech1"

FILES = [
    "config/jwt.php",
    "app/Services/Auth/MemberJwtService.php",
    "app/Http/Controllers/WorkspaceAuthController.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "app/Http/Controllers/ErrorMonitoringController.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Workspace/WorkspaceMemberService.php",
    "app/Services/Communications/CommunicationsDataService.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/views/error-monitoring/index.blade.php",
    "routes/web.php",
]

VERIFY = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApplicationError;
use App\Models\User;
use App\Services\Auth\MemberJwtService;
use Illuminate\Support\Facades\Schema;

echo 'jwt_secret_set='.(filled(config('jwt.secret')) ? 'yes' : 'no')."\n";
echo 'jwt_ttl_hours='.config('jwt.ttl_hours')."\n";

$user = User::query()->orderBy('id')->first();
if ($user) {
    $svc = app(MemberJwtService::class);
    $token = $svc->issue($user);
    $payload = $svc->parse($token);
    $expIn = (int) ($payload['exp'] ?? 0) - time();
    $ok = is_array($payload) && $expIn > 8 * 3600 && $expIn <= 9 * 3600 + 60;
    echo 'jwt_issue='.($ok ? 'ok' : 'fail').' exp_in='.$expIn."s\n";
}

$src = file_get_contents(__DIR__.'/../app/Services/Workspace/WorkspaceMemberService.php');
echo 'role_typo='.(str_contains($src, 'if (role ===') ? 'BUG' : 'fixed')."\n";
$cds = file_get_contents(__DIR__.'/../app/Services/Communications/CommunicationsDataService.php');
echo 'recording_status_safe='.(str_contains($cds, "recording_status'] ??") ? 'yes' : 'check')."\n";

if (Schema::hasTable('application_errors')) {
    $before = ApplicationError::query()->count();
    $ctl = new \App\Http\Controllers\ErrorMonitoringController();
    $m = new \ReflectionMethod($ctl, 'purgeResolvedFingerprints');
    $m->setAccessible(true);
    $removed = (int) $m->invoke($ctl);
    $after = ApplicationError::query()->count();
    echo 'errors_before='.$before.' purged='.$removed.' after='.$after."\n";
}
echo "OK\n";
'''


def deploy_new(retries: int = 8, wait_s: int = 15) -> bool:
    ssh_mod.HOST = NEW_HOST
    ssh_mod.USER = NEW_USER
    ssh_mod.PASSWORD = NEW_PASS
    ssh_mod.REMOTE_APP = "/var/www/apexone"
    os.environ["DEPLOY_PASSWORD"] = NEW_PASS
    os.environ["DEPLOY_HOST"] = NEW_HOST
    os.environ["DEPLOY_USER"] = NEW_USER

    ssh = None
    last_err = None
    for attempt in range(1, retries + 1):
        try:
            print(f"CONNECT attempt {attempt}/{retries} -> {NEW_HOST}")
            ssh = connect(timeout=20)
            print(f"CONNECTED {NEW_HOST}")
            break
        except Exception as e:
            last_err = e
            print(f"CONNECT_FAIL: {e}")
            if attempt < retries:
                time.sleep(wait_s)

    if ssh is None:
        print(f"NEW_SERVER_UNREACHABLE after {retries} tries: {last_err}")
        return False

    try:
        jwt_secret = secrets.token_urlsafe(48)
        verify_path = ROOT / "deploy" / "_verify_jwt_errors.php"
        verify_path.write_text(VERIFY, encoding="utf-8", newline="\n")

        upload_files(
            ssh,
            [(ROOT / rel, rel) for rel in FILES] + [(verify_path, "deploy/_verify_jwt_errors.php")],
            app_root="/var/www/apexone",
        )

        out = sudo_run(
            ssh,
            "cd /var/www/apexone && "
            "php -l app/Services/Auth/MemberJwtService.php && "
            "php -l app/Http/Controllers/ErrorMonitoringController.php && "
            "grep -q '^JWT_SECRET=' .env || echo 'JWT_SECRET=' >> .env; "
            "grep -q '^JWT_TTL_HOURS=' .env || echo 'JWT_TTL_HOURS=9' >> .env; "
            f"sed -i 's/^JWT_SECRET=.*/JWT_SECRET={jwt_secret}/' .env; "
            "sed -i 's/^JWT_TTL_HOURS=.*/JWT_TTL_HOURS=9/' .env; "
            "php artisan config:clear && php artisan view:clear && php artisan cache:clear && "
            "php artisan route:clear && "
            "php deploy/_verify_jwt_errors.php && "
            "curl -s -o /dev/null -w 'local_HTTP:%{http_code} TIME:%{time_total}\\n' --max-time 20 http://127.0.0.1/ || true && "
            "curl -s -o /dev/null -w 'domain_HTTP:%{http_code} TIME:%{time_total}\\n' --max-time 20 https://crm.apexonepayments.com/ || true",
        )
        print(out.encode("ascii", "replace").decode("ascii"))
        return True
    finally:
        ssh.close()


if __name__ == "__main__":
    ok = deploy_new()
    print("LIVE_OK" if ok else "LIVE_FAIL")
    sys.exit(0 if ok else 1)
