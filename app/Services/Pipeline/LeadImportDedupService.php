<?php

namespace App\Services\Pipeline;

use App\Models\WorkflowLead;
use App\Support\UsPhoneNormalizer;

class LeadImportDedupService
{
    /**
     * @param  array<int, string>  $batchPhonesInMemory  normalized phones already accepted in this import
     */
    public function shouldDiscard(
        int $workspaceId,
        ?string $rawPhone,
        array &$batchPhonesInMemory,
    ): bool {
        $normalized = UsPhoneNormalizer::normalize($rawPhone);

        if (! $normalized) {
            return false;
        }

        if (isset($batchPhonesInMemory[$normalized])) {
            return true;
        }

        $exists = WorkflowLead::query()
            ->where('normalized_phone', $normalized)
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->exists();

        if ($exists) {
            return true;
        }

        $batchPhonesInMemory[$normalized] = true;

        return false;
    }

    public function formatPhoneForStorage(?string $rawPhone): array
    {
        $normalized = UsPhoneNormalizer::normalize($rawPhone);
        $display = $normalized ? (UsPhoneNormalizer::format($normalized) ?? $rawPhone) : $rawPhone;

        return [
            'input_phone' => $display,
            'normalized_phone' => $normalized,
        ];
    }
}
