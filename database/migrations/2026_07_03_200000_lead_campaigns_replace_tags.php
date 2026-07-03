<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('workspace_id')->constrained('lead_campaigns')->nullOnDelete();
        });

        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('workflow_id')->constrained('lead_campaigns')->nullOnDelete();
            $table->index('campaign_id');
        });

        if (Schema::hasTable('lead_tag_workflow_lead')) {
            Schema::drop('lead_tag_workflow_lead');
        }

        if (Schema::hasTable('lead_tags')) {
            Schema::drop('lead_tags');
        }

        if (Schema::hasColumn('workflows', 'import_tag_ids')) {
            Schema::table('workflows', function (Blueprint $table) {
                $table->dropColumn('import_tag_ids');
            });
        }
    }

    public function down(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::dropIfExists('lead_campaigns');
    }
};
