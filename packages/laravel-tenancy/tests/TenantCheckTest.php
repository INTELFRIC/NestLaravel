<?php

namespace NestLaravel\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class TenantCheckTest extends TestCase
{
    public function test_it_flags_a_tenant_table_that_no_model_scopes(): void
    {
        // `invoices` (from TestCase) has tenant_id; the Invoice model with BelongsToTenant lives in the test namespace,
        // not in app/, so from the app's point of view nothing scopes the table → the audit must say so.
        Artisan::call('tenant:check', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $messages = array_column(array_filter($report['findings'], fn ($f) => $f['status'] === 'fail'), 'message');
        $this->assertNotEmpty(array_filter($messages, fn ($m) => str_contains($m, 'invoices')), json_encode($report));
        $this->assertSame(1, Artisan::call('tenant:check'));
    }

    public function test_it_passes_when_no_unscoped_tenant_tables_exist(): void
    {
        Schema::drop('invoices');
        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->string('body');
        });

        $this->assertSame(0, Artisan::call('tenant:check'));
    }

    public function test_it_warns_when_strict_jobs_is_off(): void
    {
        Schema::drop('invoices');
        config(['tenancy.strict_jobs' => false]);

        Artisan::call('tenant:check', ['--json' => true]);

        $warn = array_column(array_filter(json_decode(Artisan::output(), true)['findings'], fn ($f) => $f['status'] === 'warn'), 'message');
        $this->assertNotEmpty($warn);
    }
}
