<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->text('website')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->string('website')->nullable()->change();
        });
    }
};
