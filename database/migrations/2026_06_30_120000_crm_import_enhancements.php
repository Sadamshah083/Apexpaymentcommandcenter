<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('lead_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7)->default('#6366f1');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('lead_tag_workflow_lead', function (Blueprint $table) {
            $table->foreignId('workflow_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['workflow_lead_id', 'lead_tag_id']);
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->foreignId('lead_list_id')->nullable()->after('workspace_id')->constrained('lead_lists')->nullOnDelete();
            $table->json('import_tag_ids')->nullable()->after('distribution_users');
            $table->boolean('auto_assign_setters')->default(false)->after('import_tag_ids');
            $table->unsignedInteger('discarded_duplicates')->default(0)->after('failed_leads');
            $table->unsignedInteger('enriched_leads')->default(0)->after('processed_leads');
        });

        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->string('normalized_phone', 11)->nullable()->after('input_phone');
            $table->foreignId('lead_list_id')->nullable()->after('workflow_id')->constrained('lead_lists')->nullOnDelete();

            $table->index(['normalized_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_list_id');
            $table->dropIndex(['normalized_phone']);
            $table->dropColumn('normalized_phone');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_list_id');
            $table->dropColumn(['import_tag_ids', 'auto_assign_setters', 'discarded_duplicates', 'enriched_leads']);
        });

        Schema::dropIfExists('lead_tag_workflow_lead');
        Schema::dropIfExists('lead_tags');
        Schema::dropIfExists('lead_lists');
    }
};
