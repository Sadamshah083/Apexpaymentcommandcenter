<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lead_campaigns') || ! Schema::hasTable('workflows')) {
            return;
        }

        $workspaceIds = DB::table('workflows')
            ->whereNull('campaign_id')
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            if (! $workspaceId) {
                continue;
            }

            $campaignId = DB::table('lead_campaigns')
                ->where('workspace_id', $workspaceId)
                ->where('name', 'Default')
                ->value('id');

            if (! $campaignId) {
                $campaignId = DB::table('lead_campaigns')->insertGetId([
                    'workspace_id' => $workspaceId,
                    'name' => 'Default',
                    'description' => 'Auto-created campaign for pre-migration imports.',
                    'status' => 'active',
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('workflows')
                ->where('workspace_id', $workspaceId)
                ->whereNull('campaign_id')
                ->update(['campaign_id' => $campaignId]);

            if (Schema::hasColumn('workflow_leads', 'campaign_id')) {
                DB::table('workflow_leads')
                    ->whereNull('campaign_id')
                    ->whereIn('workflow_id', function ($q) use ($workspaceId) {
                        $q->select('id')
                            ->from('workflows')
                            ->where('workspace_id', $workspaceId);
                    })
                    ->update(['campaign_id' => $campaignId]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive; do not undo backfill.
    }
};
