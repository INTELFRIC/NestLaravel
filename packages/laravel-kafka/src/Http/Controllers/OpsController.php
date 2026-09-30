<?php

namespace NestLaravel\Kafka\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use NestLaravel\Kafka\Health\HealthChecker;
use NestLaravel\Kafka\Observability\Metrics;
use Throwable;

/** Operational endpoints: /liveness /startup /readiness /health /metrics (never expose these publicly). */
final class OpsController extends Controller
{
    public function __construct(private readonly HealthChecker $health) {}

    public function liveness(): JsonResponse
    {
        return response()->json($this->health->liveness());
    }

    public function startup(): JsonResponse
    {
        $r = $this->health->startup();

        return response()->json($r, $r['status'] === 'ok' ? 200 : 503);
    }

    public function readiness(): JsonResponse
    {
        $r = $this->health->readiness();

        return response()->json($r, $r['status'] === 'ok' ? 200 : 503);
    }

    public function health(): JsonResponse
    {
        $r = $this->health->full();

        return response()->json($r, $r['status'] === 'fail' ? 503 : 200);
    }

    /** Prometheus scrape endpoint. Disabled (404) unless METRICS_TOKEN is set; requires `Authorization: Bearer <token>`. */
    public function metrics(Request $request): Response
    {
        $token = (string) config('kafka.metrics.token', '');

        if ($token === '' || ! config('kafka.metrics.enabled', true)) {
            abort(404);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $this->collectPointInTimeGauges();

        return response(Metrics::render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    /** Gauges that are cheap to read at scrape time (no background job needed). */
    private function collectPointInTimeGauges(): void
    {
        Metrics::gauge('nestlaravel_process_memory_bytes', memory_get_usage(true), [], 'Memory of the scraped PHP process');
        Metrics::gauge('nestlaravel_process_memory_peak_bytes', memory_get_peak_usage(true), [], 'Peak memory of the scraped PHP process');

        if (function_exists('sys_getloadavg') && ($load = @sys_getloadavg()) !== false) {
            Metrics::gauge('nestlaravel_host_load1', $load[0], [], '1-minute load average (where available)');
        }

        try {
            DB::select('select 1');
            Metrics::gauge('nestlaravel_database_up', 1, [], 'Database reachable at scrape time');
        } catch (Throwable) {
            Metrics::gauge('nestlaravel_database_up', 0, [], 'Database reachable at scrape time');

            return;
        }

        try {
            Metrics::gauge('nestlaravel_queue_depth', (int) Queue::size(), ['queue' => (string) config('queue.connections.'.config('queue.default').'.queue', 'default')], 'Jobs waiting in the default queue');
        } catch (Throwable) {
        }

        try {
            $o = $this->health->outbox();
            Metrics::gauge('nestlaravel_outbox_pending', $o['pending'], [], 'Outbox rows waiting to be published');
            Metrics::gauge('nestlaravel_outbox_processing', $o['processing'], [], 'Outbox rows currently claimed by a publisher');
            Metrics::gauge('nestlaravel_outbox_failed', $o['failed'], [], 'Outbox rows in terminal failed state');
            Metrics::gauge('nestlaravel_outbox_oldest_pending_age_seconds', $o['oldest_pending_age_seconds'] ?? 0, [], 'Age of the oldest pending row');
        } catch (Throwable) {
            // outbox table not migrated in this service
        }
    }
}

