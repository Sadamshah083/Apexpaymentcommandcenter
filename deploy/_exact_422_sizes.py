#!/usr/bin/env python3
"""Exact byte lengths for formatOriginateResponse-style busy payloads."""
import json
from pathlib import Path
import os
import paramiko

HOST = "203.215.160.44"
USER = "issac"
ROOT = "/var/www/apexone"


def password():
    p = Path(__file__).with_name(".deploy_password")
    if p.exists():
        return p.read_text(encoding="utf-8").strip()
    return os.environ.get("DEPLOY_PASSWORD", "")


def run(c, cmd, timeout=90):
    stdin, stdout, stderr = c.exec_command(cmd, timeout=timeout)
    return stdout.read().decode("utf-8", errors="replace"), stderr.read().decode(
        "utf-8", errors="replace"
    )


def sizes(obj):
    # Laravel JsonResponse encoding options historically 0
    a = json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode()
    b = json.dumps(obj, ensure_ascii=False).encode()
    c = json.dumps(obj, separators=(",", ":"), ensure_ascii=True).encode()
    return len(a), len(b), len(c)


def main():
    payloads = []

    campaign = "6c753496-2efd-4783-aa85-eb6ec73bc512"
    did = "13133851223"

    payloads.append(
        (
            "busy_full",
            {
                "ok": False,
                "action": "originate",
                "campaign_id": campaign,
                "from": "1020",
                "caller_id_number": did,
                "internal_from": True,
                "to": "12722001232",
                "attempted": ["POST /click-to-call"],
                "error": "Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.",
                "extension_busy": True,
            },
        )
    )

    payloads.append(
        (
            "busy_full_hint",
            {
                "ok": False,
                "action": "originate",
                "campaign_id": campaign,
                "from": "1020",
                "caller_id_number": did,
                "internal_from": True,
                "to": "12722001232",
                "attempted": ["POST /click-to-call"],
                "error": "Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.",
                "extension_busy": True,
                "hint": "Your extension line is busy on Morpheus (another tab, portal phone, or stale registration). Disconnect other phones, click Connect line, wait for Registered, then call again.",
            },
        )
    )

    # note: formatOriginateResponse does NOT include hint - hint stays only in result error paths maybe
    payloads.append(
        (
            "no_answer_hint_style",
            {
                "ok": False,
                "action": "originate",
                "campaign_id": campaign,
                "from": "1020",
                "caller_id_number": did,
                "internal_from": True,
                "outcome": "no_answer",
                "to": "12722001232",
                "attempted": ["POST /click-to-call"],
                "error": "Your browser extension did not answer the Morpheus ring. Click Connect line, wait for Registered, then dial again.",
            },
        )
    )

    payloads.append(
        (
            "routing",
            {
                "ok": False,
                "action": "originate",
                "campaign_id": campaign,
                "from": "1020",
                "caller_id_number": did,
                "internal_from": True,
                "to": "12722001232",
                "attempted": ["POST /click-to-call"],
                "error": "Morpheus could not route this number. Use Call (click-to-call) with Connect line Registered — do not dial the PSTN number directly from the browser.",
                "routing_error": True,
            },
        )
    )

    # Unicode en-dash in still busy
    payloads.append(
        (
            "still_busy",
            {
                "ok": False,
                "action": "originate",
                "campaign_id": campaign,
                "from": "1020",
                "caller_id_number": did,
                "internal_from": True,
                "to": "12722001232",
                "attempted": ["POST /click-to-call"],
                "error": "Extension 1020 is still busy. Wait 10–15 seconds, click Connect line, then try again.",
                "extension_busy": True,
            },
        )
    )

    for name, obj in payloads:
        a, b, c = sizes(obj)
        mark = ""
        for n in (a, b, c):
            if n in (411, 473):
                mark = f" MATCH {n}"
            elif abs(n - 411) <= 8 or abs(n - 473) <= 8:
                mark += f" near:{n}"
        print(f"{name}: compact_u={a} spaced_u={b} compact_ascii={c}{mark}")

    # Ask PHP on server for actual formatOriginateResponse JSON length
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(HOST, username=USER, password=password(), timeout=25)

    php = r"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$z = app(App\Services\Integrations\ZoomApiService::class);
$results = [
  ['ok'=>false,'error'=>'Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.','extension_busy'=>true,'attempted'=>['POST /click-to-call']],
  ['ok'=>false,'error'=>'Extension 1020 is still busy. Wait 10–15 seconds, click Connect line, then try again.','extension_busy'=>true,'attempted'=>['POST /click-to-call']],
  ['ok'=>false,'error'=>'Morpheus could not route this number. Use Call (click-to-call) with Connect line Registered — do not dial the PSTN number directly from the browser.','routing_error'=>true,'attempted'=>['POST /click-to-call']],
  ['ok'=>false,'error'=>'Could not place outbound call.','attempted'=>['POST /click-to-call']],
];
$opts = ['campaign_id'=>'6c753496-2efd-4783-aa85-eb6ec73bc512','caller_id_number'=>'13133851223'];
foreach ($results as $i => $r) {
  $fmt = $z->formatOriginateResponse($r, '1020', '+12722001232', $opts);
  $json = json_encode($fmt);
  echo "i=$i len=".strlen($json)." body=$json\n";
}
"""
    # write temp file to avoid escaping hell
    run(c, f"cat > /tmp/fmt_len.php << 'PHP'\n{php}\nPHP")
    out, err = run(c, f"cd {ROOT} && php /tmp/fmt_len.php")
    print("PHP:\n", out)
    print("ERR:", err[-800:])

    # hangup via public methods
    hang = r"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$z = app(App\Services\Integrations\ZoomApiService::class);
$ref = new ReflectionClass($z);
$m = $ref->getMethod('listActiveCalls');
$m->setAccessible(true);
$calls = $m->invoke($z) ?: [];
echo 'active='.count($calls).PHP_EOL;
foreach ($calls as $call) {
  $uuid = $call['uuid'] ?? $call['call_uuid'] ?? '';
  echo json_encode($call).PHP_EOL;
  if ($uuid !== '') {
    $h = $ref->getMethod('hangupCall');
    // hangupCall may be public
    try { echo json_encode($z->hangupCall($uuid)).PHP_EOL; } catch (Throwable $e) { echo $e->getMessage().PHP_EOL; }
  }
}
"""
    run(c, f"cat > /tmp/hang.php << 'PHP'\n{hang}\nPHP")
    out, err = run(c, f"cd {ROOT} && php /tmp/hang.php")
    print("HANG:\n", out[-2000:], err[-500:])
    c.close()


if __name__ == "__main__":
    main()
