<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for Call Monitoring, Agent Status, and dialer queues.
 * Behavior-neutral: indexes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->index(['workspace_id', 'status'], 'workspace_user_workspace_status_idx');
        });

        Schema::table('communication_call_logs', function (Blueprint $table) {
            $table->index(['workspace_id', 'user_id', 'created_at'], 'ccl_workspace_user_created_idx');
            $table->index(['workspace_id', 'status', 'created_at'], 'ccl_workspace_status_created_idx');
        });

        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->index(
                ['assigned_user_id', 'last_contacted_at', 'contact_attempts'],
                'wl_assigned_undialed_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropIndex('workspace_user_workspace_status_idx');
        });

        Schema::table('communication_call_logs', function (Blueprint $table) {
            $table->dropIndex('ccl_workspace_user_created_idx');
            $table->dropIndex('ccl_workspace_status_created_idx');
        });

        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->dropIndex('wl_assigned_undialed_idx');
        });
    }
};
