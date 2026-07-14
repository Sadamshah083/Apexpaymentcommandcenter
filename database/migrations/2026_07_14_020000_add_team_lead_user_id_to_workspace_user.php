<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            if (! Schema::hasColumn('workspace_user', 'team_lead_user_id')) {
                $table->unsignedBigInteger('team_lead_user_id')->nullable()->after('role');
                $table->index(['workspace_id', 'team_lead_user_id'], 'workspace_user_team_lead_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            if (Schema::hasColumn('workspace_user', 'team_lead_user_id')) {
                $table->dropIndex('workspace_user_team_lead_idx');
                $table->dropColumn('team_lead_user_id');
            }
        });
    }
};
