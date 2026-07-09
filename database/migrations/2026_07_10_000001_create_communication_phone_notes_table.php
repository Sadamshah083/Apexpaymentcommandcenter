<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_phone_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('normalized_phone', 20);
            $table->text('body')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'normalized_phone']);
            $table->index(['workspace_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_phone_notes');
    }
};
