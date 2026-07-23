<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_call_logs', function (Blueprint $table) {
            $table->index(['workspace_id', 'disposition'], 'ccl_workspace_disposition_idx');
            $table->index(['workspace_id', 'user_id', 'disposition', 'created_at'], 'ccl_workspace_user_disposition_created_idx');
        });

        Schema::table('workflow_leads', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_leads', 'last_disposition')) {
                $table->index(
                    ['assigned_user_id', 'last_disposition', 'last_contacted_at', 'contact_attempts'],
                    'wl_assigned_disposition_contact_idx'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('communication_call_logs', function (Blueprint $table) {
            $table->dropIndex('ccl_workspace_disposition_idx');
            $table->dropIndex('ccl_workspace_user_disposition_created_idx');
        });

        Schema::table('workflow_leads', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_leads', 'last_disposition')) {
                $table->dropIndex('wl_assigned_disposition_contact_idx');
            }
        });
    }
};
