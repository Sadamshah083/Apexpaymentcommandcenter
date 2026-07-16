#!/usr/bin/env python3
"""Probe Morpheus reachability + circuit breaker state from new server."""
import shlex
import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PW = "balitech1"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=30)

cmd = r"""
cd /var/www/apexone
echo '=== Morpheus HTTPS probe ==='
curl -sk --connect-timeout 5 --max-time 12 -o /dev/null -w 'https_api=%{http_code} time=%{time_total}\n' https://apexone.morpheus.cx/api/v1/call-control/users?limit=1 || echo FAIL
echo '=== WSS port ==='
timeout 5 bash -c 'echo >/dev/tcp/apexone.morpheus.cx/7443' && echo wss_port_open || echo wss_port_closed
echo '=== Circuit breaker cache ==='
sudo -u www-data php artisan tinker --execute="
\$cb = app(\App\Services\Integrations\MorpheusCircuitBreaker::class);
echo 'class=' . get_class(\$cb) . PHP_EOL;
" 2>&1 | tail -n 20
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
  $cb = app(App\Services\Integrations\MorpheusCircuitBreaker::class);
  $ref = new ReflectionClass($cb);
  foreach ($ref->getMethods() as $m) {
    if ($m->getNumberOfRequiredParameters()===0 && in_array($m->getName(), ["isOpen","open","status","state","available"])) {
      try { var_export($m->invoke($cb)); echo " ".$m->getName()."\n"; } catch (Throwable $e) {}
    }
  }
  echo "methods: ".implode(",", array_map(fn($m)=>$m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC)))."\n";
} catch (Throwable $e) { echo $e->getMessage(),"\n"; }
try {
  $z = app(App\Services\Integrations\ZoomApiService::class);
  $r = $z->isConfigured() ? "configured" : "not";
  echo "zoom=$r\n";
} catch (Throwable $e) { echo $e->getMessage(),"\n"; }
'
echo '=== redis/cache keys morpheus ==='
sudo -u www-data php artisan tinker --execute="
foreach (['morpheus_circuit','morpheus.circuit','morpheus_circuit_open','integrations.morpheus.circuit'] as \$k) {
  echo \$k.'='.json_encode(Cache::get(\$k)).PHP_EOL;
}
" 2>&1 | tail -n 30
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=90)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
