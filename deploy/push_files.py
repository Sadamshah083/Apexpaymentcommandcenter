#!/usr/bin/env python3
"""
Fast hotfix deploy: upload specific files in one tarball (seconds, not minutes).

Usage:
  python deploy/push_files.py app/Services/Workflow/WorkflowExtractor.php
  python deploy/push_files.py --restart-queue app/Foo.php app/Bar.php
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run_batch, upload_files


def main() -> int:
    parser = argparse.ArgumentParser(description="Upload files to production in one transfer.")
    parser.add_argument("files", nargs="+", help="Paths relative to repo root")
    parser.add_argument("--restart-queue", action="store_true", help="Signal queue workers to restart after current job")
    parser.add_argument("--clear-config", action="store_true", help="Run artisan config:cache after upload")
    args = parser.parse_args()

    pairs: list[tuple[Path, str]] = []
    for rel in args.files:
        local = ROOT / rel
        if not local.is_file():
            print(f"Missing: {rel}", file=sys.stderr)
            return 1
        pairs.append((local, rel.replace("\\", "/")))

    ssh = connect()
    print(f"Uploading {len(pairs)} file(s) in one archive...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    post: list[str] = []
    if args.clear_config:
        post.append(f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear")
        post.append(f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache")
    if args.restart_queue:
        restart_queue_workers(ssh)
    if post:
        sudo_run_batch(ssh, post)

    ssh.close()
    print("Done.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
