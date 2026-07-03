<?php

use App\Models\WorkflowLead;
use App\Support\LeadStageSync;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        WorkflowLead::query()
            ->orderBy('id')
            ->chunkById(200, function ($leads) {
                foreach ($leads as $lead) {
                    $stage = LeadStageSync::mergeStage($lead)['stage'] ?? $lead->stage;
                    if ($stage !== $lead->stage) {
                        $lead->update(['stage' => $stage]);
                    }
                }
            });
    }

    public function down(): void
    {
        //
    }
};
