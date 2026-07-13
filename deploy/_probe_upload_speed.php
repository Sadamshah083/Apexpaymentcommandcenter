<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

$tmp = storage_path('app/tmp_upload_probe.csv');
@mkdir(dirname($tmp), 0775, true);
file_put_contents($tmp, "Business Name,Phone,City\nAcme Corp,555-0100,Houston\nBeta LLC,555-0101,Dallas\n");

$user = User::query()->orderBy('id')->first();
$workspace = $user
    ? Workspace::query()->find($user->current_workspace_id)
    : Workspace::query()->orderBy('id')->first();

if (! $workspace) {
    echo json_encode(['ok' => false, 'error' => 'no workspace'], JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}

$service = app(WorkflowService::class);
$file = new UploadedFile($tmp, 'tmp_upload_probe.csv', 'text/csv', null, true);

$t0 = microtime(true);
$workflow = $service->createFromUpload($workspace, 'Upload speed probe', $file, 'import_only');
$t1 = microtime(true);
$workflow = $service->applyAutoMappingIfNeeded($workflow);
$t2 = microtime(true);

$payload = [
    'ok' => true,
    'create_ms' => round(($t1 - $t0) * 1000, 1),
    'map_ms' => round(($t2 - $t1) * 1000, 1),
    'total_ms' => round(($t2 - $t0) * 1000, 1),
    'mapped_business' => $workflow->column_mapping['business_name'] ?? null,
    'fast_path' => true,
];

@unlink($tmp);
if ($workflow->file_path) {
    Storage::disk('local')->delete($workflow->file_path);
}
$workflow->delete();

echo json_encode($payload, JSON_PRETTY_PRINT).PHP_EOL;
