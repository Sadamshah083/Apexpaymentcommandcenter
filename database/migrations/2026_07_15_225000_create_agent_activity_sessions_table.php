<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_activity_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // break | lunch
            $table->string('status', 16)->default('active'); // active | ended | expired
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('planned_seconds');
            $table->string('ended_reason', 32)->nullable(); // manual | auto | call | replaced
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'type']);
            $table->index(['user_id', 'status']);
            $table->index(['ends_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_activity_sessions');
    }
};
