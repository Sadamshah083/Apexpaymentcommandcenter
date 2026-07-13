#!/usr/bin/env python3
"""Probe workflow upload latency on production."""

from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect

PHP = r'''
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

$tmp = storage_path("app/tmp_upload_probe.csv");
@mkdir(dirname($tmp), 0775, true);
file_put_contents($tmp, "Business Name,Phone,City\nAcme Corp,555-0100,Houston\nBeta LLC,555-0101,Dallas\n");

$user = User::query()->orderBy("id")->first();
$workspace = $user ? Workspace::query()->find($user->current_workspace_id) : Workspace::query()->orderBy("id")->first();
if (!$workspace) {
    echo json_encode(["ok" => false, "error" => "no workspace"]);
    exit(1);
}

$service = app(WorkflowService::class);
$file = new UploadedFile($tmp, "tmp_upload_probe.csv", "text/csv", null, true);

$t0 = microtime(true);
$workflow = $service->createFromUpload($workspace, "Upload speed probe", $file, "import_only");
$t1 = microtime(true);
$workflow = $service->applyAutoMappingIfNeeded($workflow);
$t2 = microtime(true);

$createMs = round(($t1 - $t0) * 1000, 1);
$mapMs = round(($t2 - $t1) * 1000, 1);
$totalMs = round(($t2 - $t0) * 1000, 1);

@unlink($tmp);
if ($workflow->file_path) {
    Storage::disk("local")->delete($workflow->file_path);
}
$workflow->delete();

echo json_encode([
    "ok" => true,
    "create_ms" => $createMs,
    "map_ms" => $mapMs,
    "total_ms" => $totalMs,
    "mapped_business" => $workflow->column_mapping["business_name"] ?? null,
    "fast_path" => true,
], JSON_PRETTY_PRINT);
'''


def main() -> int:
    ssh = connect()
    remote_php = f"{REMOTE_APP}/storage/app/_probe_upload_speed.php"
    write_cmd = f"cat > {shlex.quote(remote_php)} <<'PHP'\n{PHP}\nPHP"
    full_write = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(write_cmd)}"
    _, stdout, stderr = ssh.exec_command(full_write)
    stdout.channel.recv_exit_status()

    run = (
        f"cd {REMOTE_APP} && sudo -u www-data php {remote_php} "
        f"&& rm -f {remote_php}"
    )
    full_run = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(run)}"
    _, stdout, stderr = ssh.exec_command(full_run)
    code = stdout.channel.recv_exit_status()
    print(stdout.read().decode(errors="replace"))
    err = stderr.read().decode(errors="replace")
    if err.strip():
        print(err)
    ssh.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
