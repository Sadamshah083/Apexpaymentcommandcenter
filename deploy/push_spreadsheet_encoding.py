#!/usr/bin/env python3
"""Deploy spreadsheet encoding + header detection fixes."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Support/SpreadsheetText.php",
    "app/Support/SpreadsheetHeaderDetector.php",
    "app/Support/LeadContactDisplay.php",
    "app/Services/Workflow/WorkflowAiMapper.php",
    "app/Jobs/ProcessWorkflowJob.php",
    "app/Services/Crm/CrmCsvImporter.php",
    "app/Services/Crm/CrmColumnMapper.php",
    "resources/views/workflows/show.blade.php",
    "resources/js/workspace-sync.js",
    "resources/js/portal-dashboard.js",
    "tests/Unit/Support/SpreadsheetImportEncodingTest.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Building frontend assets if needed...")
    # workspace-sync / portal-dashboard go through Vite — rebuild JS
    out = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data npm run build",
        check=False,
    )
    print(out[-2000:] if out else "(no build output)")

    print("Clearing caches + running unit test...")
    print(
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
                f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
                f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
                f"cd {REMOTE_APP} && sudo -u www-data php artisan test --filter=SpreadsheetImportEncodingTest",
            ],
        )
    )

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    print("Spreadsheet encoding fix deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
