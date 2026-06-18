<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->unsignedInteger('ingestion_row_offset')->default(0)->after('distribution_cursor');
            $table->boolean('ingestion_complete')->default(false)->after('ingestion_row_offset');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn(['ingestion_row_offset', 'ingestion_complete']);
        });
    }
};
