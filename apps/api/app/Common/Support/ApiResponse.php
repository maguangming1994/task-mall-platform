<?php

namespace App\Common\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200, string $message = 'success'): JsonResponse
    {
        return response()->json([
            'code' => 'OK',
            'message' => $message,
            'data' => $data,
            'request_id' => self::requestId(),
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => self::requestId(),
        ];

        if ($details !== null) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    public static function requestId(): string
    {
        return (string) request()->attributes->get('request_id', '');
    }
}
