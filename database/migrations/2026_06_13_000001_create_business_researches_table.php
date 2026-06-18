<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_researches', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->text('address')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('pending');
            $table->string('owner_name')->nullable();
            $table->string('owner_title')->nullable();
            $table->json('co_owners')->nullable();
            $table->json('emails')->nullable();
            $table->json('phones')->nullable();
            $table->string('payment_processor')->nullable();
            $table->string('pos_system')->nullable();
            $table->string('field_service_software')->nullable();
            $table->string('business_type')->nullable();
            $table->boolean('is_franchise')->nullable();
            $table->string('franchise_brand')->nullable();
            $table->text('summary')->nullable();
            $table->json('structured_data')->nullable();
            $table->json('sources')->nullable();
            $table->json('search_queries')->nullable();
            $table->string('confidence')->nullable();
            $table->text('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('business_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_researches');
    }
};
