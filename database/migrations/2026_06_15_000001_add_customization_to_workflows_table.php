<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->text('custom_prompt')->nullable();
            $table->json('verification_toggles')->nullable();
            $table->json('distribution_users')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn(['custom_prompt', 'verification_toggles', 'distribution_users']);
        });
    }
};
