#!/usr/bin/env python3
"""Match 422 Content-Lengths to known originate error payloads + hang orphaned probe calls."""
import json
import os
import urllib.parse
from pathlib import Path

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


def main():
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(HOST, username=USER, password=password(), timeout=25)

    # Access log without sudo (often world-readable or group)
    for path in [
        "/var/log/nginx/access.log",
        "/var/log/nginx/access.log.1",
        f"{ROOT}/storage/logs/nginx-access.log",
    ]:
        out, err = run(c, f"tail -n 200 {path} 2>/dev/null | grep originate | tail -20")
        if out.strip():
            print(f"LOG {path}:\n{out}")
            break
        print(f"no {path}: {err.strip()}")

    # Compute body sizes that match 411 / 473
    offline = "Extension 1020 is not connected — open the Phone panel and click Connect line before dialing. "
    # fetch real message from PHP
    out, _ = run(
        c,
        f"cd {ROOT} && php -r \""
        "require 'vendor/autoload.php'; "
        "\\$app=require 'bootstrap/app.php'; "
        "\\$app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); "
        "\\$s=app(App\\\\Services\\\\Communications\\\\CommunicationsAgentService::class); "
        "echo \\$s->extensionOfflineDialMessage('1020');"
        "\"",
    )
    print("OFFLINE_MSG:", repr(out[:500]))

    candidates = []

    def add(name, obj):
        raw = json.dumps(obj, separators=(",", ":"), ensure_ascii=False)
        # Laravel often uses JSON_UNESCAPED_UNICODE / pretty? Default JsonResponse
        raw2 = json.dumps(obj, ensure_ascii=False)
        candidates.append((name, len(raw.encode()), len(raw2.encode()), raw2[:200]))

    add(
        "offline_api",
        {
            "ok": False,
            "extension_offline": True,
            "webphone_required": True,
            "error": out.strip() or offline,
        },
    )
    add(
        "dest_invalid",
        {
            "ok": False,
            "error": "Enter a valid phone number with at least 10 digits (e.g. +12722001232).",
        },
    )
    add(
        "campaign",
        {
            "ok": False,
            "error": "Morpheus campaign_id is required for outbound calls. Set MORPHEUS_DEFAULT_CAMPAIGN_ID in .env or create an active campaign in Morpheus CX.",
        },
    )
    add(
        "validation_from",
        {
            "message": "The from extension field is required.",
            "errors": {"from_extension": ["The from extension field is required."]},
        },
    )
    add(
        "validation_dest",
        {
            "message": "The destination field is required.",
            "errors": {"destination": ["The destination field is required."]},
        },
    )

    # Simulate a fuller formatOriginateResponse-ish payload
    add(
        "fmt_busy_1020",
        {
            "ok": False,
            "from": "1020",
            "to": "+12722001232",
            "error": "Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.",
            "extension_busy": True,
            "outcome": "extension_busy",
            "hint": "Your extension line is busy on Morpheus (another tab, portal phone, or stale registration). Disconnect other phones, click Connect line, wait for Registered, then call again.",
        },
    )
    add(
        "fmt_busy_still",
        {
            "ok": False,
            "from": "1020",
            "to": "+12722001232",
            "error": "Extension 1020 is still busy. Wait 10–15 seconds, click Connect line, then try again.",
            "extension_busy": True,
        },
    )
    add(
        "fmt_routing",
        {
            "ok": False,
            "from": "1020",
            "to": "+12722001232",
            "error": "Morpheus could not route this number. Use Call (click-to-call) with Connect line Registered — do not dial the PSTN number directly from the browser.",
            "routing_error": True,
            "hint": "Morpheus could not route this number. Use Call (click-to-call) with Connect line Registered — do not dial the PSTN number directly from the browser.",
        },
    )

    print("\nSIZE MATCHES (compact / default):")
    for name, a, b, snip in candidates:
        mark = ""
        if a in (411, 473) or b in (411, 473):
            mark = " <<<< MATCH"
        if abs(a - 411) < 5 or abs(b - 411) < 5 or abs(a - 473) < 5 or abs(b - 473) < 5:
            mark += " ~near"
        print(f"  {name}: compact={a} default={b}{mark}")
        print(f"    {snip}")

    # Hang up any active calls from our probe
    out, err = run(
        c,
        f"cd {ROOT} && php -r \""
        "require 'vendor/autoload.php'; "
        "\\$app=require 'bootstrap/app.php'; "
        "\\$app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); "
        "\\$z=app(App\\\\Services\\\\Integrations\\\\ZoomApiService::class); "
        "\\$calls=\\$z->listActiveCalls() ?: []; "
        "echo 'active='.count(\\$calls).PHP_EOL; "
        "foreach (\\$calls as \\$call) { "
        "  \\$uuid=\\$call['uuid'] ?? \\$call['call_uuid'] ?? ''; "
        "  \\$cid=\\$call['cid_num'] ?? \\$call['caller_id_number'] ?? ''; "
        "  \\$dest=\\$call['dest'] ?? \\$call['destination_number'] ?? ''; "
        "  echo \\$uuid.' | '.\\$cid.' -> '.\\$dest.PHP_EOL; "
        "  if (\\$uuid) { \\$r=\\$z->hangupCall(\\$uuid); echo 'hangup '.json_encode(\\$r).PHP_EOL; } "
        "}"
        "\"",
        timeout=60,
    )
    print("ACTIVE/HANGUP:\n", out[-3000:], err[-500:])

    # Peek laravel.log around 17:31 without sudo
    out, _ = run(
        c,
        "grep -n '17:3[0-5]' /var/www/apexone/storage/logs/laravel.log | tail -5; "
        "wc -l /var/www/apexone/storage/logs/laravel.log; "
        "grep -iE 'originate|click.to.call|422|extension_busy|Could not' "
        "/var/www/apexone/storage/logs/laravel.log | tail -30",
    )
    print("LARAVEL:\n", out[-4000:])

    c.close()


if __name__ == "__main__":
    main()
