#!/usr/bin/env python3
"""Inspect Morpheus active-call field shapes from production."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

PHP = r"""
cd APP
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = app(App\Services\Integrations\ZoomApiService::class);
$ref = new ReflectionClass($svc);
$m = $ref->getMethod("listActiveCalls");
$m->setAccessible(true);
$calls = $m->invoke($svc);
echo "count=" . count($calls) . PHP_EOL;
foreach (array_slice($calls, 0, 5) as $i => $c) {
  echo "--- call $i ---" . PHP_EOL;
  foreach (["uuid","status","state","phone_number","destination_number","extension","from","to","direction","age_sec","billsec","duration_sec","bridge_uuid","bridged_to","answered","answer_time"] as $k) {
    if (array_key_exists($k, $c)) {
      $v = $c[$k];
      if (is_bool($v)) $v = $v ? "true" : "false";
      echo "$k=" . (is_scalar($v) ? $v : json_encode($v)) . PHP_EOL;
    }
  }
  echo "all_keys=" . implode(",", array_keys($c)) . PHP_EOL;
}
// Also hit raw HTTP once for schema
$host = config("integrations.morpheus.host");
$key = config("integrations.morpheus.api_key");
$ch = curl_init("https://{$host}/api/v1/call-control/calls");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ["Authorization: Bearer {$key}", "Accept: application/json"],
  CURLOPT_TIMEOUT => 8,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "raw_http=$code len=" . strlen((string)$body) . PHP_EOL;
$j = json_decode((string)$body, true);
if (is_array($j)) {
  $list = $j["calls"] ?? $j["data"] ?? (isset($j[0]) ? $j : []);
  echo "raw_top_keys=" . implode(",", array_keys($j)) . PHP_EOL;
  if (is_array($list) && isset($list[0]) && is_array($list[0])) {
    echo "raw0_keys=" . implode(",", array_keys($list[0])) . PHP_EOL;
    echo "raw0=" . json_encode($list[0]) . PHP_EOL;
  } else {
    echo "raw_empty_or_shape=" . substr((string)$body, 0, 500) . PHP_EOL;
  }
}
'
""".replace("APP", REMOTE_APP)


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, PHP))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
