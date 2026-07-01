#!/usr/bin/env python3
"""Re-queue workflow leads stuck in extracting with no pending jobs."""

import os
import shlex
import sys

import paramiko

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
USER = os.environ.get("DEPLOY_USER", "issac")
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "btdev")
WORKFLOW_ID = int(os.environ.get("WORKFLOW_ID", "1"))


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

    php = f"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$workflowId = {WORKFLOW_ID};
$workflow = App\\Models\\Workflow::find($workflowId);
if (! $workflow) {{ fwrite(STDERR, 'Workflow not found\\n'); exit(1); }}
$reset = App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'extracting')->update(['status' => 'imported']);
$workflow->update(['status' => 'extracting', 'error_message' => null]);
$leadIds = App\\Models\\WorkflowLead::where('workflow_id', $workflowId)->where('status', 'imported')->orderBy('row_number')->pluck('id');
foreach ($leadIds as $leadId) {{
    App\\Jobs\\ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt);
}}
echo 'reset_' . $reset . ' queued_' . $leadIds->count();
"""
    print(run(ssh, f"cd /var/www/apexone && sudo -u www-data php -r {shlex.quote(php)}"))
    print(run(ssh, "systemctl restart apexone-queue"))

    status_php = f"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$wf = App\\Models\\Workflow::find({WORKFLOW_ID});
echo "workflow status={{$wf->status}} failed={{$wf->failed_leads}}\\n";
$counts = App\\Models\\WorkflowLead::where('workflow_id', {WORKFLOW_ID})->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c','status');
foreach ($counts as $s => $c) echo "{{$s}}: {{$c}}\\n";
echo 'jobs=' . DB::table('jobs')->count();
"""
    print(run(ssh, f"cd /var/www/apexone && sudo -u www-data php -r {shlex.quote(status_php)}"))
    ssh.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"REQUEUE FAILED: {exc}", file=sys.stderr)
        raise
