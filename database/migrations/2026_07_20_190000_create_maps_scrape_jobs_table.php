<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maps_scrape_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_mode', 20)->default('quick'); // quick|state
            $table->string('state')->nullable();
            $table->string('business')->nullable();
            $table->string('search_query')->nullable();
            $table->string('scrape_mode', 20)->default('city'); // city|grid|both
            $table->unsignedInteger('per_search')->default(20);
            $table->boolean('small_business_only')->default(true);
            $table->string('status', 30)->default('pending');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->string('progress_message')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->string('csv_path')->nullable();
            $table->string('export_zip_path')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maps_scrape_jobs');
    }
};
