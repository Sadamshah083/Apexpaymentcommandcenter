<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            if (! Schema::hasColumn('workspace_user', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('team_lead_user_id');
                $table->index(['workspace_id', 'campaign_id'], 'workspace_user_campaign_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            if (Schema::hasColumn('workspace_user', 'campaign_id')) {
                $table->dropIndex('workspace_user_campaign_idx');
                $table->dropColumn('campaign_id');
            }
        });
    }
};
