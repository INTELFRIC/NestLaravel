<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('correlation_id', 191);
            // running | waiting | compensating | completed | compensated | failed
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('step_index')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('waiting_for', 191)->nullable();
            $table->json('failure_events')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->json('context');
            $table->json('history');
            $table->text('last_error')->nullable();
            $table->string('tenant_id', 64)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'correlation_id']);
            $table->index(['status', 'deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_instances');
    }
};
