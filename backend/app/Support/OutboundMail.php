<?php

namespace App\Support;

use RuntimeException;

class OutboundMail
{
    public static function assertConfigured(): void
    {
        if (self::isConfigured()) {
            return;
        }

        throw new RuntimeException(
            'Envio de e-mail não configurado no servidor. '
            .'Configure MAIL_MAILER=smtp com MAIL_HOST=smtp-relay.brevo.com, '
            .'MAIL_USERNAME e MAIL_PASSWORD (chave SMTP do Brevo).'
        );
    }

    public static function isConfigured(): bool
    {
        $mailer = (string) config('mail.default', 'log');

        if ($mailer === 'log') {
            return app()->environment('local');
        }

        if ($mailer === 'array') {
            return app()->environment('testing');
        }

        if ($mailer === 'smtp') {
            if (filled(env('MAIL_URL'))) {
                return true;
            }

            $host = trim((string) env('MAIL_HOST', ''));

            if ($host === '' || in_array($host, ['127.0.0.1', 'localhost'], true)) {
                return false;
            }

            return filled(env('MAIL_USERNAME')) && filled(env('MAIL_PASSWORD'));
        }

        if ($mailer === 'resend') {
            return filled(config('services.resend.key'));
        }

        if ($mailer === 'postmark') {
            return filled(config('services.postmark.key'));
        }

        return true;
    }
}
