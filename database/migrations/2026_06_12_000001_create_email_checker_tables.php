<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('source_file')->nullable();
            $table->unsignedInteger('total_count')->default(0);
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('risky_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);
            $table->timestamps();
        });

        Schema::create('verification_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_list_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('normalized_email')->index();
            $table->string('domain')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('final_score', 5, 2)->nullable();
            $table->json('tags')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['email_list_id', 'status']);
            $table->unique(['email_list_id', 'normalized_email']);
        });

        Schema::create('verification_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_contact_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->string('status');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });

        Schema::create('disposable_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('role_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('prefix')->unique();
            $table->timestamps();
        });

        Schema::create('free_provider_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->timestamps();
        });

        Schema::create('deliverability_tests', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('sending_ip')->nullable();
            $table->string('dkim_selector')->nullable();
            $table->json('spf_result')->nullable();
            $table->json('dkim_result')->nullable();
            $table->json('dmarc_result')->nullable();
            $table->json('mx_result')->nullable();
            $table->json('ptr_result')->nullable();
            $table->json('dnsbl_result')->nullable();
            $table->decimal('overall_score', 4, 2)->default(0);
            $table->json('recommendations')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('content_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('subject')->nullable();
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->json('scores')->nullable();
            $table->json('highlights')->nullable();
            $table->json('suggestions')->nullable();
            $table->decimal('overall_score', 4, 2)->default(0);
            $table->decimal('spam_score', 4, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('spam_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('name');
            $table->string('pattern');
            $table->string('match_type')->default('regex');
            $table->decimal('weight', 4, 2);
            $table->string('target')->default('any');
            $table->text('description')->nullable();
            $table->text('suggestion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('reputation_logs', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('metric');
            $table->string('value')->nullable();
            $table->text('notes')->nullable();
            $table->date('recorded_at');
            $table->timestamps();

            $table->index(['domain', 'recorded_at']);
        });

        Schema::create('inbound_test_inboxes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email_address')->unique();
            $table->string('status')->default('waiting');
            $table->timestamp('expires_at')->nullable();
            $table->json('parsed_headers')->nullable();
            $table->json('auth_results')->nullable();
            $table->longText('raw_message')->nullable();
            $table->foreignId('content_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deliverability_test_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('overall_score', 4, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_test_inboxes');
        Schema::dropIfExists('reputation_logs');
        Schema::dropIfExists('spam_rules');
        Schema::dropIfExists('content_analyses');
        Schema::dropIfExists('deliverability_tests');
        Schema::dropIfExists('free_provider_domains');
        Schema::dropIfExists('role_prefixes');
        Schema::dropIfExists('disposable_domains');
        Schema::dropIfExists('verification_results');
        Schema::dropIfExists('email_contacts');
        Schema::dropIfExists('verification_batches');
        Schema::dropIfExists('email_lists');
    }
};
