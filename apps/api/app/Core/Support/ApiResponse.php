<?php

namespace App\Core\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => self::mergeMeta($meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>|list<string>|null  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(
        string $message,
        mixed $errors = null,
        int $status = 400,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'data' => null,
            'message' => $message,
            'meta' => self::mergeMeta($meta),
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function mergeMeta(array $meta): array
    {
        return array_merge(self::defaultMeta(), $meta);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultMeta(): array
    {
        $meta = [];

        if (app()->bound('request_id')) {
            $meta['request_id'] = (string) app('request_id');
        } elseif (app()->bound('correlation_id')) {
            $meta['request_id'] = (string) app('correlation_id');
        }

        if (app()->bound('correlation_id')) {
            $meta['correlation_id'] = (string) app('correlation_id');
        }

        return $meta;
    }
}
