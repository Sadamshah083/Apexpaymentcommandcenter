#!/usr/bin/env python3
"""Verify LINE picker extension/DID fix on production."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()
    checks = [
        (
            "CommunicationsAgentService resolveOutboundDid",
            "grep -c resolveOutboundDid /var/www/apexone/app/Services/Communications/CommunicationsAgentService.php",
        ),
        (
            "UsPhoneNormalizer e164",
            "grep -c 'function e164' /var/www/apexone/app/Support/UsPhoneNormalizer.php",
        ),
        (
            "dialer-extension-field resolver",
            "grep -c lineDidResolver /var/www/apexone/resources/views/communications/partials/dialer-extension-field.blade.php",
        ),
        (
            "billing did 1020",
            "grep 1020 /var/www/apexone/config/morpheus_billing_dids.php",
        ),
        (
            "dialerExtensionsFast sample",
            "cd /var/www/apexone && php artisan tinker --execute=\""
            "\\$u=\\App\\Models\\User::first(); "
            "\\$w=\\App\\Models\\Workspace::first(); "
            "\\$ext=app(\\App\\Services\\Communications\\CommunicationsAgentService::class)"
            "->dialerExtensionsFast(\\$u,\\$w,'admin.'); "
            "echo count(\\$ext).' lines; '; "
            "echo (\\$ext[0]['extension_num']??'?').' '.(\\$ext[0]['caller_id_num']??'?');\"",
        ),
    ]

    ok = True
    for label, cmd in checks:
        out = sudo_run(ssh, cmd, check=False)
        print(f"[{label}]")
        print(out or "(empty)")
        if label.startswith("CommunicationsAgentService") and out.strip() == "0":
            ok = False
        if label.startswith("UsPhoneNormalizer") and out.strip() == "0":
            ok = False
        if label.startswith("dialer-extension") and out.strip() == "0":
            ok = False
        print()

    ssh.close()
    if not ok:
        print("MISSING: backend files not deployed — run deploy with CommunicationsAgentService.php")
        return 1
    print("Live server checks complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
