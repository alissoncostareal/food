<?php

namespace App\Support;

use RuntimeException;

class AdminUrl
{
    public static function base(): string
    {
        foreach (self::candidates() as $url) {
            if ($url !== '' && ! self::isLocalHost($url)) {
                return $url;
            }
        }

        if (app()->environment('local')) {
            return rtrim(trim((string) config('services.admin.url', 'http://localhost:5175')), '/');
        }

        throw new RuntimeException(
            'ADMIN_DASHBOARD_URL não está configurada. Defina a URL pública do painel (ex.: https://admin.partiumenu.com.br).'
        );
    }

    public static function invite(string $token): string
    {
        return self::base().'/convite/'.$token;
    }

    public static function resetPassword(string $token, string $email): string
    {
        return self::base().'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function candidates(): array
    {
        return array_values(array_filter(array_map(
            fn (?string $url) => rtrim(trim((string) $url), '/'),
            [
                config('services.admin.url'),
                env('ADMIN_DASHBOARD_URL'),
            ]
        )));
    }

    private static function isLocalHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)
            || str_ends_with($host, '.local');
    }
}
