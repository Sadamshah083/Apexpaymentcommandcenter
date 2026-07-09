<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_call_logs', function (Blueprint $table) {
            $table->string('recording_file_id', 64)->nullable()->after('note');
            $table->string('recording_source', 32)->nullable()->after('recording_file_id');
            $table->string('recording_status', 24)->nullable()->default('none')->after('recording_source');

            $table->index(['workspace_id', 'recording_status']);
        });
    }

    public function down(): void
    {
        Schema::table('communication_call_logs', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'recording_status']);
            $table->dropColumn(['recording_file_id', 'recording_source', 'recording_status']);
        });
    }
};
