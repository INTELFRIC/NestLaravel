<?php

namespace App\Infrastructure\Gateway;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GatewayProxyController extends Controller
{
    public function __construct(
        private readonly GatewayProxy $proxy,
    ) {}

    /**
     * Proxy /api/v1/{prefix}/{path} to the registered microservice.
     */
    public function __invoke(Request $request, ?string $path = null): Response
    {
        $service = (string) $request->route('service');

        return $this->proxy->forward($service, $path ?? '', $request);
    }
}
