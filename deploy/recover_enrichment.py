#!/usr/bin/env python3
"""Pause stuck enrichment, drain the queue, and optionally set API keys on production."""

from __future__ import annotations

import os
import shlex
import sys

import paramiko

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
USER = os.environ.get("DEPLOY_USER", "issac")
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "btdev")
WORKFLOW_ID = os.environ.get("WORKFLOW_ID", "1")
GEMINI_API_KEY = os.environ.get("GEMINI_API_KEY", "")
OPENROUTER_API_KEY = os.environ.get("OPENROUTER_API_KEY", "")


def run(ssh: paramiko.SSHClient, command: str) -> str:
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(command)}"
    _, stdout, stderr = ssh.exec_command(full)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    if code != 0:
        raise RuntimeError(f"Command failed ({code}):\n{out}\n{err}")
    return out.strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    db_pass = run(ssh, "grep '^DB_PASSWORD=' /var/www/apexone/.env | cut -d= -f2- | tr -d '\"'")
    mysql = f"mysql -u apexone -p{shlex.quote(db_pass)} apexone"

    print("Pausing workflow and resetting in-flight leads...")
    run(
        ssh,
        (
            f"{mysql} -e "
            + shlex.quote(
                f"UPDATE workflows SET status='paused' WHERE id={WORKFLOW_ID}; "
                f"UPDATE workflow_leads SET status='pending' WHERE workflow_id={WORKFLOW_ID} AND status='extracting';"
            )
        ),
    )

    print("Draining queued enrichment jobs...")
    run(ssh, f"{mysql} -e 'DELETE FROM jobs;'")

    if GEMINI_API_KEY:
        print("Setting GEMINI_API_KEY...")
        run(
            ssh,
            f"cd /var/www/apexone && sed -i 's|^GEMINI_API_KEY=.*|GEMINI_API_KEY={shlex.quote(GEMINI_API_KEY)}|' .env",
        )
    if OPENROUTER_API_KEY:
        print("Setting OPENROUTER_API_KEY...")
        run(
            ssh,
            f"cd /var/www/apexone && sed -i 's|^OPENROUTER_API_KEY=.*|OPENROUTER_API_KEY={shlex.quote(OPENROUTER_API_KEY)}|' .env",
        )

    if GEMINI_API_KEY or OPENROUTER_API_KEY:
        run(ssh, "cd /var/www/apexone && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan config:cache")

    print(run(ssh, f"{mysql} -e 'SELECT id,status,total_leads,processed_leads,failed_leads FROM workflows WHERE id={WORKFLOW_ID}'"))
    print(run(ssh, f"{mysql} -e 'SELECT status,COUNT(*) c FROM workflow_leads WHERE workflow_id={WORKFLOW_ID} GROUP BY status'"))
    print(run(ssh, f"{mysql} -e 'SELECT COUNT(*) pending_jobs FROM jobs'"))

    ssh.close()
    print("Recovery complete. Deploy the app update, add API keys if needed, then use Retry failed leads or Resume.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"RECOVERY FAILED: {exc}", file=sys.stderr)
        raise
