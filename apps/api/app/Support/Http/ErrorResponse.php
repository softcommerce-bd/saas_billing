<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;

final class ErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(ErrorCode $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code->value, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
