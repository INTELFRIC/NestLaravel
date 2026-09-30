<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_probes_are_open_and_split_by_purpose(): void
    {
        $this->getJson('/liveness')->assertOk()->assertJson(['status' => 'alive']);
        $this->getJson('/readiness')->assertOk()->assertJsonPath('checks.database.status', 'ok');
        $this->getJson('/startup')->assertOk();
        $this->getJson('/health')->assertOk()->assertJsonStructure(['status', 'checks' => ['database', 'redis', 'cache', 'kafka']]);
    }

    public function test_metrics_are_off_until_a_scrape_token_is_configured(): void
    {
        $this->get('/metrics')->assertNotFound();

        config(['kafka.metrics.token' => 'scrape-token-for-tests']);
        $this->get('/metrics')->assertStatus(401);
        $this->get('/metrics', ['Authorization' => 'Bearer scrape-token-for-tests'])
            ->assertOk()
            ->assertSee('nestlaravel_database_up 1');
    }

    public function test_the_reliability_tables_are_migrated(): void
    {
        foreach (['outbox_messages', 'inbox_events', 'saga_instances'] as $table) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($table), $table);
        }
    }
}
