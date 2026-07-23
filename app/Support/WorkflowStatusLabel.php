<?php

namespace App\Support;

use App\Models\Workflow;

class WorkflowStatusLabel
{
    /**
     * @return array{label: string, class: string}
     */
    public static function for(?string $status, ?string $processingMode = null): array
    {
        $status = (string) $status;
        $uploadOnly = in_array((string) $processingMode, ['import_only', 'store_only'], true);

        if ($uploadOnly) {
            return match ($status) {
                'mapping' => ['label' => 'Setup', 'class' => 'app-status-pill-setup'],
                'pending' => ['label' => 'Queued', 'class' => 'app-status-pill-queued'],
                'extracting' => ['label' => 'Uploading', 'class' => 'app-status-pill-uploading'],
                'paused' => ['label' => 'Paused', 'class' => 'app-status-pill-paused'],
                'completed' => ['label' => 'Uploaded', 'class' => 'app-status-pill-complete'],
                'failed' => ['label' => 'Failed', 'class' => 'app-status-pill-failed'],
                default => ['label' => $status !== '' ? $status : 'Unknown', 'class' => 'app-status-pill-queued'],
            };
        }

        return match ($status) {
            'mapping' => ['label' => 'Setup', 'class' => 'app-status-pill-setup'],
            'pending' => ['label' => 'Queued', 'class' => 'app-status-pill-queued'],
            'extracting' => ['label' => 'Enriching', 'class' => 'app-status-pill-enriching'],
            'paused' => ['label' => 'Paused', 'class' => 'app-status-pill-paused'],
            'completed' => ['label' => 'Complete', 'class' => 'app-status-pill-complete'],
            'failed' => ['label' => 'Failed', 'class' => 'app-status-pill-failed'],
            default => ['label' => $status !== '' ? $status : 'Unknown', 'class' => 'app-status-pill-queued'],
        };
    }

    public static function label(?string $status, ?string $processingMode = null): string
    {
        return self::for($status, $processingMode)['label'];
    }
}
