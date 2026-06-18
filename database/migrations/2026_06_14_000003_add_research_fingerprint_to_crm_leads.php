<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('research_fingerprint', 64)->nullable()->after('row_number');
            $table->index(['campaign_id', 'research_fingerprint']);
            $table->index(['research_fingerprint', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'research_fingerprint']);
            $table->dropIndex(['research_fingerprint', 'status']);
            $table->dropColumn('research_fingerprint');
        });
    }
};
