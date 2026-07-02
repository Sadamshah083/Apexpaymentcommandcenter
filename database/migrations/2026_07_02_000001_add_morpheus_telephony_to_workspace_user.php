<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->string('morpheus_user_id', 36)->nullable()->after('module_permissions');
            $table->string('morpheus_extension_id', 36)->nullable()->after('morpheus_user_id');
            $table->string('morpheus_extension_num', 32)->nullable()->after('morpheus_extension_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn([
                'morpheus_user_id',
                'morpheus_extension_id',
                'morpheus_extension_num',
            ]);
        });
    }
};
