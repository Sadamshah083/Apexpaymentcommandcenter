<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('processing_mode')->default('full_pipeline')->after('status');
        });

        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->string('import_mode')->default('pipeline')->after('workflow_id');
            $table->string('pipeline_phase')->default('imported')->after('import_mode');
            $table->string('setter_status')->nullable()->after('pipeline_phase');
            $table->string('closer_status')->nullable()->after('setter_status');
            $table->foreignId('assigned_setter_id')->nullable()->after('assigned_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_closer_id')->nullable()->after('assigned_setter_id')->constrained('users')->nullOnDelete();
            $table->timestamp('appointment_settled_at')->nullable()->after('assigned_closer_id');
            $table->text('handoff_notes')->nullable()->after('appointment_settled_at');

            $table->index('pipeline_phase');
            $table->index('setter_status');
            $table->index('closer_status');
        });

        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phase');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['workflow_lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignments');

        Schema::table('workflow_leads', function (Blueprint $table) {
            $table->dropForeign(['assigned_setter_id']);
            $table->dropForeign(['assigned_closer_id']);
            $table->dropColumn([
                'import_mode',
                'pipeline_phase',
                'setter_status',
                'closer_status',
                'assigned_setter_id',
                'assigned_closer_id',
                'appointment_settled_at',
                'handoff_notes',
            ]);
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('processing_mode');
        });
    }
};
