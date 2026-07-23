<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_errors', function (Blueprint $table) {
            $table->id();
            $table->string('level', 32)->default('error')->index();
            $table->string('exception_class')->nullable()->index();
            $table->text('message');
            $table->longText('trace')->nullable();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('method', 16)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_errors');
    }
};
