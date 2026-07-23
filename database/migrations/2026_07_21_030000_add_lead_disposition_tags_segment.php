<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_leads', 'last_disposition')) {
                $table->string('last_disposition', 120)->nullable()->after('last_contacted_at');
            }
            if (! Schema::hasColumn('workflow_leads', 'tags')) {
                $table->json('tags')->nullable()->after('raw_row');
            }
            if (! Schema::hasColumn('workflow_leads', 'segment')) {
                $table->string('segment', 120)->nullable()->after('tags')->index();
            }
        });

        Schema::table('workflows', function (Blueprint $table) {
            if (! Schema::hasColumn('workflows', 'import_tags')) {
                $table->json('import_tags')->nullable()->after('campaign_id');
            }
            if (! Schema::hasColumn('workflows', 'import_segment')) {
                $table->string('import_segment', 120)->nullable()->after('import_tags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_leads', 'last_disposition')) {
                $table->dropColumn('last_disposition');
            }
            if (Schema::hasColumn('workflow_leads', 'tags')) {
                $table->dropColumn('tags');
            }
            if (Schema::hasColumn('workflow_leads', 'segment')) {
                $table->dropColumn('segment');
            }
        });

        Schema::table('workflows', function (Blueprint $table) {
            if (Schema::hasColumn('workflows', 'import_tags')) {
                $table->dropColumn('import_tags');
            }
            if (Schema::hasColumn('workflows', 'import_segment')) {
                $table->dropColumn('import_segment');
            }
        });
    }
};
