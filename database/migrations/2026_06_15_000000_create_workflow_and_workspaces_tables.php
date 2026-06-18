<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('marketer'); // admin, marketer
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        if (!Schema::hasColumn('users', 'current_workspace_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('current_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            });
        }

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('pending'); // pending, mapping, extracting, completed, failed
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->json('sheets')->nullable(); // list of sheets in Excel file
            $table->string('selected_sheet')->nullable();
            $table->json('column_mapping')->nullable();
            $table->unsignedInteger('total_leads')->default(0);
            $table->unsignedInteger('processed_leads')->default(0);
            $table->unsignedInteger('failed_leads')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, extracting, completed, failed
            $table->unsignedInteger('row_number');

            // Mapped inputs
            $table->string('business_name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('country')->nullable();
            $table->string('website')->nullable();
            $table->string('input_phone')->nullable();
            $table->string('input_email')->nullable();
            $table->json('raw_row')->nullable();

            // AI Extracted Enriched Data
            $table->string('owner_name')->nullable();
            $table->string('direct_phone')->nullable();
            $table->string('direct_email')->nullable();
            $table->string('payment_processor')->nullable();
            $table->text('system_integration')->nullable();
            $table->string('primary_service')->nullable();
            $table->text('operating_hours')->nullable();
            $table->text('markdown_report')->nullable();

            // CRM Record Management
            $table->string('stage')->default('lead'); // lead, contacted, follow_up, interested, closed_won, closed_lost
            $table->decimal('sale_value', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamp('followup_at')->nullable();
            $table->timestamp('schedule_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            // Metadata / Logging
            $table->text('error_message')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamp('researched_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index('assigned_user_id');
            $table->index('stage');
            $table->index('business_name');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 500)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_workspace_id']);
            $table->dropColumn('current_workspace_id');
        });

        Schema::dropIfExists('workflow_leads');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
