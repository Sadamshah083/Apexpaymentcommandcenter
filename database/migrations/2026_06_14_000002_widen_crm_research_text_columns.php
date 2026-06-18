<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropIndex('crm_leads_owner_name_index');
            $table->dropIndex('crm_leads_payment_processor_index');
        });

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->text('owner_name')->nullable()->change();
            $table->string('direct_phone', 80)->nullable()->change();
            $table->string('direct_email', 255)->nullable()->change();
            $table->string('physical_address', 500)->nullable()->change();
            $table->text('primary_service')->nullable()->change();
            $table->string('payment_processor', 255)->nullable()->change();
            $table->string('pos_system', 255)->nullable()->change();
            $table->string('field_service_software', 255)->nullable()->change();
            $table->text('business_type')->nullable()->change();
            $table->string('franchise_brand', 255)->nullable()->change();
        });

        Schema::table('business_researches', function (Blueprint $table) {
            $table->text('owner_name')->nullable()->change();
            $table->string('payment_processor', 255)->nullable()->change();
            $table->string('pos_system', 255)->nullable()->change();
            $table->string('field_service_software', 255)->nullable()->change();
            $table->text('business_type')->nullable()->change();
            $table->string('franchise_brand', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
