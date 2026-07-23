<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lead_dispositions')) {
            return;
        }

        Schema::create('lead_dispositions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('workflow_lead_id')->nullable()->index();
            $table->unsignedBigInteger('communication_call_log_id')->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->string('call_uuid', 128)->nullable()->index();
            $table->string('disposition', 120);
            $table->text('note')->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->string('dial_mode', 16)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // Append-only history: same phone / lead / disposition may repeat forever.
            $table->index(['workspace_id', 'phone', 'created_at']);
            $table->index(['workspace_id', 'workflow_lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_dispositions');
    }
};
