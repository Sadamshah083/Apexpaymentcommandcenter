<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('original_filename')->nullable();
            $table->string('status')->default('pending'); // pending, importing, processing, completed, failed
            $table->unsignedInteger('total_leads')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('processing_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('csv_headers')->nullable();
            $table->json('column_mapping')->nullable();
            $table->text('import_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('crm_campaigns')->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            // Import status
            $table->string('status')->default('pending'); // pending, processing, completed, failed, skipped

            // Normalized input (from CSV)
            $table->string('business_name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 50)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('country', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('input_phone')->nullable();
            $table->string('input_email')->nullable();
            $table->json('raw_row')->nullable();
            $table->json('extra_fields')->nullable();

            // Enriched intelligence (AI output)
            $table->string('owner_name')->nullable();
            $table->string('owner_title')->nullable();
            $table->string('direct_phone')->nullable();
            $table->string('direct_email')->nullable();
            $table->json('phones')->nullable();
            $table->json('emails')->nullable();
            $table->string('physical_address')->nullable();
            $table->string('primary_service')->nullable();
            $table->text('operating_hours')->nullable();
            $table->string('payment_processor')->nullable();
            $table->string('pos_system')->nullable();
            $table->string('field_service_software')->nullable();
            $table->string('business_type')->nullable();
            $table->boolean('is_franchise')->nullable();
            $table->string('franchise_brand')->nullable();
            $table->text('summary')->nullable();
            $table->string('confidence')->nullable();
            $table->json('structured_data')->nullable();
            $table->json('sources')->nullable();
            $table->json('search_queries')->nullable();
            $table->text('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamp('researched_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index('business_name');
            $table->index('owner_name');
            $table->index('payment_processor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_campaigns');
    }
};
