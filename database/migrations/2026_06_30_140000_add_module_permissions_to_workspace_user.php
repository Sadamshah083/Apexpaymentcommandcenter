<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('workspace_user', 'module_permissions')) {
            Schema::table('workspace_user', function (Blueprint $table) {
                $table->json('module_permissions')->nullable()->after('joined_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspace_user', 'module_permissions')) {
            Schema::table('workspace_user', function (Blueprint $table) {
                $table->dropColumn('module_permissions');
            });
        }
    }
};
