<?php

namespace App\Support;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;

class ThrottleResponse
{
    public static function fromException(ThrottleRequestsException $exception): JsonResponse
    {
        $headers = $exception->getHeaders();
        $retryAfter = (int) ($headers['Retry-After'] ?? $headers['retry-after'] ?? 60);
        $retryAfter = max(1, $retryAfter);

        $message = self::messageForSeconds($retryAfter);

        return response()->json([
            'message' => $message,
            'error' => $message,
            'retry_after' => $retryAfter,
        ], 429, $headers);
    }

    public static function messageForSeconds(int $seconds): string
    {
        if ($seconds >= 60) {
            $minutes = (int) ceil($seconds / 60);

            return "Muitas tentativas. Aguarde {$minutes} minuto(s) e tente novamente.";
        }

        return "Muitas tentativas. Aguarde {$seconds} segundo(s) e tente novamente.";
    }
}
