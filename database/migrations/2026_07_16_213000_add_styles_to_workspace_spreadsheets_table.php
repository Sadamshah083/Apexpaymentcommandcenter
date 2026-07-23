<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_spreadsheets', function (Blueprint $table) {
            if (! Schema::hasColumn('workspace_spreadsheets', 'styles')) {
                $table->json('styles')->nullable()->after('rows');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspace_spreadsheets', function (Blueprint $table) {
            if (Schema::hasColumn('workspace_spreadsheets', 'styles')) {
                $table->dropColumn('styles');
            }
        });
    }
};
