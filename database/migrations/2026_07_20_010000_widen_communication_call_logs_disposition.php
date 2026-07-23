<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communication_call_logs')) {
            return;
        }

        DB::statement('ALTER TABLE communication_call_logs MODIFY disposition VARCHAR(120) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('communication_call_logs')) {
            return;
        }

        DB::statement('ALTER TABLE communication_call_logs MODIFY disposition VARCHAR(64) NULL');
    }
};
