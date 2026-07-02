<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('morpheus_call_uuid', 36)->nullable()->index();
            $table->string('direction', 16)->default('outbound');
            $table->string('from_extension', 32)->nullable();
            $table->string('from_phone', 32)->nullable();
            $table->string('to_phone', 32)->nullable();
            $table->string('disposition', 64)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 32)->default('initiated');
            $table->unsignedInteger('duration_sec')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_call_logs');
    }
};
