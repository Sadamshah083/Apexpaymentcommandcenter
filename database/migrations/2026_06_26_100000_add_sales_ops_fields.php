<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->unsignedSmallInteger('contact_attempts')->default(0)->after('stage');
            $table->string('tier')->default('tier_1')->after('contact_attempts');
            $table->decimal('monthly_processing_volume', 14, 2)->nullable()->after('sale_value');
            $table->string('current_processor')->nullable()->after('monthly_processing_volume');
            $table->string('pricing_model')->nullable()->after('current_processor');
            $table->date('contract_expiration_date')->nullable()->after('pricing_model');
            $table->json('pain_points')->nullable()->after('contract_expiration_date');
            $table->string('offer_type')->nullable()->after('pain_points');
            $table->timestamp('discovery_completed_at')->nullable()->after('offer_type');
            $table->foreignId('discovery_completed_by')->nullable()->after('discovery_completed_at')->constrained('users')->nullOnDelete();
            $table->boolean('meeting_qualified')->default(false)->after('discovery_completed_by');
            $table->timestamp('meeting_qualified_at')->nullable()->after('meeting_qualified');
            $table->string('reactivation_source')->nullable()->after('meeting_qualified_at');
            $table->timestamp('reactivation_eligible_at')->nullable()->after('reactivation_source');
            $table->boolean('is_nurture')->default(false)->after('reactivation_eligible_at');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_lead_id')->constrained('workflow_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('outcome')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workflow_lead_id', 'created_at']);
            $table->index(['user_id', 'type', 'created_at']);
        });

        $stageMap = [
            'lead' => 'new_lead',
            'contacted' => 'attempted_contact',
            'interested' => 'follow_up',
        ];

        foreach ($stageMap as $from => $to) {
            DB::table('workflow_leads')->where('stage', $from)->update(['stage' => $to]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');

        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discovery_completed_by');
            $table->dropColumn([
                'contact_attempts',
                'tier',
                'monthly_processing_volume',
                'current_processor',
                'pricing_model',
                'contract_expiration_date',
                'pain_points',
                'offer_type',
                'discovery_completed_at',
                'meeting_qualified',
                'meeting_qualified_at',
                'reactivation_source',
                'reactivation_eligible_at',
                'is_nurture',
            ]);
        });

        $reverseMap = [
            'new_lead' => 'lead',
            'attempted_contact' => 'contacted',
        ];

        foreach ($reverseMap as $from => $to) {
            DB::table('workflow_leads')->where('stage', $from)->update(['stage' => $to]);
        }
    }
};
