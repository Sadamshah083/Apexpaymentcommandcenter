<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->string('status')->default('active')->after('role');
            $table->timestamp('invited_at')->nullable()->after('status');
            $table->timestamp('joined_at')->nullable()->after('invited_at');
        });

        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('username')->nullable();
            $table->string('role')->default('marketer');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'email']);
            $table->index('expires_at');
        });

        Schema::create('workspace_sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'id']);
            $table->index(['workspace_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_sync_events');
        Schema::dropIfExists('workspace_invitations');

        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn(['status', 'invited_at', 'joined_at']);
        });
    }
};
