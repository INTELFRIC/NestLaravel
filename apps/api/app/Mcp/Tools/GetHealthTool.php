<?php

namespace App\Mcp\Tools;

use App\Observability\Health\HealthChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns the platform health report (app, database, Redis, optional Kafka).')]
#[IsReadOnly]
#[IsIdempotent]
class GetHealthTool extends Tool
{
    public function __construct(private readonly HealthChecker $health) {}

    public function handle(Request $request): ResponseFactory
    {
        $report = $this->health->full();

        return Response::structured([
            'status' => $report['status'],
            'checks' => $report['checks'],
            'endpoints' => [
                'live' => '/health/live',
                'ready' => '/health/ready',
                'full' => '/health',
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
