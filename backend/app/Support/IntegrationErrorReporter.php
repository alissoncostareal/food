<?php

namespace App\Support;

use App\Models\IntegrationErrorLog;
use Illuminate\Support\Str;
use Throwable;

class IntegrationErrorReporter
{
    public static function report(string $channel, string $action, Throwable|string $error, array $context = []): array
    {
        $ref = strtoupper(Str::random(8));
        $raw = $error instanceof Throwable ? $error->getMessage() : $error;
        $publicMessage = self::sanitize($raw);

        IntegrationErrorLog::query()->create([
            'error_ref' => $ref,
            'channel' => $channel,
            'action' => $action,
            'store_id' => $context['store_id'] ?? null,
            'public_message' => $publicMessage,
            'details' => $raw,
            'context' => $context ?: null,
        ]);

        return [
            'error_ref' => $ref,
            'public_message' => $publicMessage,
        ];
    }

    public static function storeMessage(string $channel, string $action, Throwable|string $error, array $context = []): string
    {
        $reported = self::report($channel, $action, $error, $context);

        return "{$reported['public_message']} (código: {$reported['error_ref']})";
    }

    /**
     * @return array{message: ?string, error_ref: ?string}
     */
    public static function parseStored(?string $stored): array
    {
        if (blank($stored)) {
            return ['message' => null, 'error_ref' => null];
        }

        if (preg_match('/\(código:\s*([A-Z0-9]+)\)\s*$/u', $stored, $matches)) {
            return [
                'message' => trim((string) preg_replace('/\s*\(código:\s*[A-Z0-9]+\)\s*$/u', '', $stored)),
                'error_ref' => $matches[1],
            ];
        }

        return [
            'message' => self::sanitize($stored),
            'error_ref' => null,
        ];
    }

    public static function sanitize(?string $message): string
    {
        $message = trim((string) $message);

        if ($message === '') {
            return 'Erro interno. Tente novamente ou contate o suporte.';
        }

        if (str_contains($message, 'cURL error 28')) {
            return 'Serviço externo não respondeu a tempo. Tente novamente em alguns minutos.';
        }

        if (preg_match('/cURL error \d+/i', $message)) {
            return 'Falha de comunicação com o serviço externo.';
        }

        if (str_contains($message, 'Unauthorized') || str_contains($message, '401')) {
            return 'Credenciais inválidas ou não autorizadas no serviço externo.';
        }

        $message = (string) preg_replace('#https?://[^\s\)\]]+#', '[url]', $message);
        $message = (string) preg_replace('/\b[a-f0-9]{32,}\b/i', '[token]', $message);
        $message = (string) preg_replace('/apikey[=:\s][^\s]+/i', 'apikey=[oculto]', $message);

        return Str::limit(trim($message), 220);
    }

    /**
     * @return array{message: string, error_ref: ?string}
     */
    public static function response(string $userMessage, ?string $errorRef = null): array
    {
        $payload = ['message' => $userMessage];

        if (filled($errorRef)) {
            $payload['error_ref'] = $errorRef;
        }

        return $payload;
    }
}
