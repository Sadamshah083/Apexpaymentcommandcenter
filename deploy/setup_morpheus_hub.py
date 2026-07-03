#!/usr/bin/env python3
"""Configure Morpheus CX on production and link agent extensions."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, set_env_vars, sudo_run, sudo_run_batch, upload_files

LOCAL_ENV = ROOT / ".env"

MORPHEUS_ENV = {
    "MORPHEUS_HOST": "apexone.morpheus.cx",
    "MORPHEUS_PORTAL_URL": "https://apexone.morpheus.cx/",
    "MORPHEUS_DIAL_METHOD": "auto",
    "MORPHEUS_SIP_PARAMS": "user=phone",
    "MORPHEUS_OUTBOUND_PREFIX": "",
    "MORPHEUS_SIP_HOST": "apexone.morpheus.cx",
    "COMMUNICATIONS_DEFAULT_CALLER_ID": "1001",
    "COMMUNICATIONS_USER_FALLBACK": "true",
    "COMMUNICATIONS_USER_FALLBACK_ON_EMPTY": "true",
}


def parse_env_key(path: Path, key: str) -> str:
    if not path.exists():
        return ""
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.strip().startswith(f"{key}="):
            return line.split("=", 1)[1].strip().strip('"').strip("'")
    return ""


def main() -> int:
    api_key = parse_env_key(LOCAL_ENV, "MORPHEUS_API_KEY")
    platform_key = parse_env_key(LOCAL_ENV, "MORPHEUS_PLATFORM_API_KEY")
    campaign_id = parse_env_key(LOCAL_ENV, "MORPHEUS_DEFAULT_CAMPAIGN_ID")

    env_updates = dict(MORPHEUS_ENV)
    if api_key:
        env_updates["MORPHEUS_API_KEY"] = api_key
    if platform_key:
        env_updates["MORPHEUS_PLATFORM_API_KEY"] = platform_key
    if campaign_id:
        env_updates["MORPHEUS_DEFAULT_CAMPAIGN_ID"] = campaign_id

    ssh = connect()
    upload_files(
        ssh,
        [
            (ROOT / "scripts/probe_morpheus_api.php", "scripts/probe_morpheus_api.php"),
            (ROOT / "scripts/sync_morpheus_extensions.php", "scripts/sync_morpheus_extensions.php"),
        ],
    )
    set_env_vars(ssh, env_updates)

    sudo_run_batch(ssh, [
        "cd /var/www/apexone && sudo -u www-data php artisan migrate --force",
        "cd /var/www/apexone && sudo -u www-data php artisan config:clear",
        "cd /var/www/apexone && sudo -u www-data php artisan config:cache",
    ])

    print("=== Morpheus API probe ===")
    probe = sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php scripts/probe_morpheus_api.php", check=False)
    print(probe[-4000:] if len(probe) > 4000 else probe)

    print("\n=== Sync workspace extensions ===")
    sync = sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php scripts/sync_morpheus_extensions.php", check=False)
    print(sync)

    sudo_run_batch(ssh, [
        "cd /var/www/apexone && sudo -u www-data php artisan cache:clear",
        "systemctl restart apexone-queue php8.3-fpm",
    ])
    ssh.close()
    print("\nMorpheus hub setup complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
