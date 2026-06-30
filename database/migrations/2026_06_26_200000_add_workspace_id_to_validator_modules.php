<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['deliverability_tests', 'content_analyses', 'reputation_logs', 'business_researches'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'workspace_id')) {
                    $blueprint->foreignId('workspace_id')->nullable()->after('id')->constrained()->nullOnDelete();
                }
                if (! Schema::hasColumn($table, 'user_id')) {
                    $blueprint->foreignId('user_id')->nullable()->after('workspace_id')->constrained()->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['deliverability_tests', 'content_analyses', 'reputation_logs', 'business_researches'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'user_id')) {
                    $blueprint->dropConstrainedForeignId('user_id');
                }
                if (Schema::hasColumn($table, 'workspace_id')) {
                    $blueprint->dropConstrainedForeignId('workspace_id');
                }
            });
        }
    }
};
