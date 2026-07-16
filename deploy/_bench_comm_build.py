#!/usr/bin/env python3
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
os.environ.setdefault("DEPLOY_PASSWORD", "SadamShah123")
import deploy._ssh as m

m.PASSWORD = os.environ["DEPLOY_PASSWORD"]
ssh = m.connect()
out = m.sudo_run(
    ssh,
    r"""
php -r '
require "/var/www/apexone/vendor/autoload.php";
$app = require "/var/www/apexone/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$u = App\Models\User::query()->where("email","like","%admin%")->orWhere("is_admin",1)->first();
if (!$u) { $u = App\Models\User::query()->first(); }
if (!$u) { echo "no-user\n"; exit; }
auth()->login($u);
$req = Illuminate\Http\Request::create("/admin/communications", "GET");
$req->setUserResolver(fn () => $u);
$t = microtime(true);
try {
  $svc = app(App\Services\Communications\CommunicationsInboxService::class);
  $payload = $svc->build($req, "admin.");
  $ms = (microtime(true) - $t) * 1000;
  echo "build_ms=".round($ms,1)." logs=".count($payload["callLogs"]??[])." ext=".count($payload["morpheusExtensions"]??[])."\n";
} catch (Throwable $e) {
  echo "err=".$e->getMessage()."\n";
}
'
""",
    check=False,
)
print(out)
ssh.close()
