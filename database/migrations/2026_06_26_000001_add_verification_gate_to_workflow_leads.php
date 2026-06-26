<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->string('verification_status')->nullable()->after('status');
            $table->json('verification_snapshot')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verification_snapshot');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'verification_status',
                'verification_snapshot',
                'verified_at',
                'rejection_reason',
            ]);
        });
    }
};
