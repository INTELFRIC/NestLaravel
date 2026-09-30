<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox v2: publisher claims (locked_by/locked_at → PROCESSING state), last_error, tenant_id.
 * Purely additive: 1.0.x publishers keep working against the migrated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('available_at');
            $table->string('locked_by', 64)->nullable()->after('locked_at');
            $table->text('last_error')->nullable()->after('locked_by');
            $table->string('tenant_id', 64)->nullable()->after('correlation_id');
            $table->index(['status', 'locked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'locked_at']);
            $table->dropColumn(['locked_at', 'locked_by', 'last_error', 'tenant_id']);
        });
    }
};
