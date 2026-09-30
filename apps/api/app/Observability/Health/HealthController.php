<?php

namespace App\Observability\Health;

use App\Core\Support\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __construct(
        private readonly HealthChecker $healthChecker,
    ) {}

    /**
     * Liveness probe — process is up.
     */
    public function live(): JsonResponse
    {
        return $this->respond($this->healthChecker->live(), 'Liveness check completed.');
    }

    /**
     * Readiness probe — required dependencies are available.
     */
    public function ready(): JsonResponse
    {
        return $this->respond($this->healthChecker->ready(), 'Readiness check completed.');
    }

    /**
     * Full health report including optional dependencies.
     */
    public function full(): JsonResponse
    {
        return $this->respond($this->healthChecker->full(), 'Health check completed.');
    }

    /**
     * @param  array{status: string, checks: array<string, array<string, mixed>>}  $result
     */
    private function respond(array $result, string $message): JsonResponse
    {
        if ($result['status'] === 'ok') {
            return ApiResponse::success(
                data: $result,
                message: $message,
            );
        }

        return ApiResponse::error(
            message: $message,
            errors: $result,
            status: 503,
        );
    }
}
