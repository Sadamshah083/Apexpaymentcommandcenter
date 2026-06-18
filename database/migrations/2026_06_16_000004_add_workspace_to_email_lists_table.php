<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_lists', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('workspace_id')->constrained()->nullOnDelete();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('email_lists', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropColumn(['workspace_id', 'user_id']);
        });
    }
};
