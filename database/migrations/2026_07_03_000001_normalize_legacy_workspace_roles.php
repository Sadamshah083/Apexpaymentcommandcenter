<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspace_user')
            ->where('role', 'marketer')
            ->orWhere('role', 'sdr')
            ->update(['role' => 'appointment_setter']);

        DB::table('workspace_user')
            ->where('role', 'account_executive')
            ->update(['role' => 'closer']);
    }

    public function down(): void
    {
        // Legacy roles cannot be restored reliably.
    }
};
