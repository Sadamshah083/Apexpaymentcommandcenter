#!/usr/bin/env python3
"""Set Gemini/OpenRouter keys on production and resume workflow enrichment."""

from __future__ import annotations

import os
import re
import shlex
import sys
from pathlib import Path

import paramiko

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
USER = os.environ.get("DEPLOY_USER", "issac")
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "btdev")
WORKFLOW_ID = int(os.environ.get("WORKFLOW_ID", "1"))
CLEAR_GEMINI = os.environ.get("CLEAR_GEMINI", "").lower() in {"1", "true", "yes"}

WORKFLOW_ENV_DEFAULTS = {
    "WORKFLOW_GEMINI_MODEL": "gemini-2.5-flash",
    "WORKFLOW_GEMINI_FALLBACK_MODELS": "gemini-2.5-pro",
    "WORKFLOW_GEMINI_MAX_OUTPUT_TOKENS": "2048",
    "WORKFLOW_GEMINI_THINKING_BUDGET": "0",
    "WORKFLOW_GEMINI_GOOGLE_SEARCH": "false",
    "WORKFLOW_GEMINI_TIMEOUT": "120",
    "WORKFLOW_WEB_SEARCH_QUERIES": "2",
    "WORKFLOW_OPENROUTER_MAX_TOKENS": "2048",
    "WORKFLOW_OPENROUTER_WEB_SEARCH": "false",
}


def load_local_env() -> dict[str, str]:
    path = Path(__file__).resolve().parents[1] / ".env"
    if not path.is_file():
        return {}
    values: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        values[key.strip()] = val.strip().strip('"')
    return values


def env_or_local(name: str, local: dict[str, str]) -> str:
    return os.environ.get(name, "") or local.get(name, "")


def run(ssh: paramiko.SSHClient, command: str) -> str:
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(command)}"
    _, stdout, stderr = ssh.exec_command(full)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    if code != 0:
        raise RuntimeError(f"Command failed ({code}):\n{out}\n{err}")
    return out.strip()


def set_env_var(ssh: paramiko.SSHClient, key: str, value: str) -> None:
    script = f"""
import pathlib, re
path = pathlib.Path('/var/www/apexone/.env')
text = path.read_text()
env_key = {key!r}
env_val = {value!r}
pattern = re.compile(r'^' + re.escape(env_key) + r'=.*$', re.M)
line = env_key + '=' + env_val
text = pattern.sub(line, text) if pattern.search(text) else text.rstrip() + '\\n' + line + '\\n'
path.write_text(text)
"""
    run(ssh, f"python3 -c {shlex.quote(script)}")


def main() -> int:
    local = load_local_env()
    gemini_api_key = env_or_local("GEMINI_API_KEY", local)
    openrouter_api_key = env_or_local("OPENROUTER_API_KEY", local)

    if not gemini_api_key and not openrouter_api_key and not CLEAR_GEMINI:
        print("Set GEMINI_API_KEY and/or OPENROUTER_API_KEY in the environment or local .env.", file=sys.stderr)
        return 1

    if CLEAR_GEMINI and not openrouter_api_key:
        print("CLEAR_GEMINI requires OPENROUTER_API_KEY as the active provider.", file=sys.stderr)
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    if gemini_api_key:
        print("Configuring GEMINI_API_KEY...")
        set_env_var(ssh, "GEMINI_API_KEY", gemini_api_key)
    elif CLEAR_GEMINI:
        print("Clearing GEMINI_API_KEY (use OpenRouter fallback)...")
        set_env_var(ssh, "GEMINI_API_KEY", "")
    if openrouter_api_key:
        print("Configuring OPENROUTER_API_KEY...")
        set_env_var(ssh, "OPENROUTER_API_KEY", openrouter_api_key)

    print("Configuring WORKFLOW_* cheap enrichment settings...")
    for key, value in WORKFLOW_ENV_DEFAULTS.items():
        set_env_var(ssh, key, value)

    uploads = [
        ("config/workflow_enrichment.php", "/var/www/apexone/config/workflow_enrichment.php"),
        ("app/Services/Workflow/WorkflowExtractor.php", "/var/www/apexone/app/Services/Workflow/WorkflowExtractor.php"),
        ("app/Services/Workflow/WorkflowProviderStatusService.php", "/var/www/apexone/app/Services/Workflow/WorkflowProviderStatusService.php"),
        ("app/Services/BusinessResearch/OpenRouterClient.php", "/var/www/apexone/app/Services/BusinessResearch/OpenRouterClient.php"),
    ]
    base = Path(__file__).resolve().parents[1]
    sftp = ssh.open_sftp()
    for local_rel, remote in uploads:
        local_path = base / local_rel
        if local_path.is_file():
            tmp = f"/tmp/{local_path.name}"
            sftp.put(str(local_path), tmp)
            run(ssh, f"cp {tmp} {remote} && chown www-data:www-data {remote}")
    sftp.close()

    print("Refreshing Laravel config and queue workers...")
    run(ssh, "chown www-data:www-data /var/www/apexone/.env")
    run(ssh, "cd /var/www/apexone && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan config:cache")
    run(ssh, "systemctl restart apexone-queue")

    print("Resetting failed leads and resuming enrichment...")
    php = f"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$workflowId = {WORKFLOW_ID};
$workflow = App\\Models\\Workflow::find($workflowId);
if (! $workflow) {{ fwrite(STDERR, 'Workflow not found\\n'); exit(1); }}
App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'failed')->update(['status' => 'imported', 'error_message' => null]);
App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'extracting')->update(['status' => 'imported']);
$workflow->update(['status' => 'extracting', 'failed_leads' => 0, 'error_message' => null]);
$leadIds = App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'imported')->orderBy('row_number')->pluck('id');
foreach ($leadIds as $leadId) {{
    App\\Jobs\\ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt);
}}
echo 'queued_' . $leadIds->count();
"""
    run(ssh, f"cd /var/www/apexone && sudo -u www-data php -r {shlex.quote(php)}")

    db_pass = run(ssh, "grep '^DB_PASSWORD=' /var/www/apexone/.env | cut -d= -f2- | tr -d '\"'")
    mysql = f"mysql -u apexone -p{shlex.quote(db_pass)} apexone"
    print(run(ssh, f"{mysql} -e 'SELECT id,status,total_leads,processed_leads,failed_leads FROM workflows WHERE id={WORKFLOW_ID}'"))
    print(run(ssh, f"{mysql} -e 'SELECT status,COUNT(*) c FROM workflow_leads WHERE workflow_id={WORKFLOW_ID} GROUP BY status'"))
    print(run(ssh, f"{mysql} -e 'SELECT COUNT(*) pending_jobs FROM jobs'"))
    verify = """
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo config('gemini.api_key') ? 'gemini_ok' : 'gemini_missing';
echo ' ';
echo config('openrouter.api_key') ? 'openrouter_ok' : 'openrouter_missing';
echo ' model=' . (config('workflow_enrichment.gemini_model') ?: config('gemini.model'));
"""
    print(run(ssh, f"cd /var/www/apexone && sudo -u www-data php -r {shlex.quote(verify)}"))

    ssh.close()
    print("Enrichment resumed.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"CONFIGURE FAILED: {exc}", file=sys.stderr)
        raise
