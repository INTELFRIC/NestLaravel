<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional inbox (dedup) table. Must live in the SAME database as the business tables the handlers write,
 * so the dedup row and the business effects commit or roll back together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('kafka.inbox.table', 'inbox_events'), function (Blueprint $table) {
            $table->id();
            $table->string('consumer', 120);
            $table->string('event_id', 191);
            $table->string('event_type', 191)->nullable();
            $table->string('correlation_id', 191)->nullable();
            $table->string('tenant_id', 64)->nullable();
            $table->timestamp('processed_at')->useCurrent();

            $table->unique(['consumer', 'event_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('kafka.inbox.table', 'inbox_events'));
    }
};
