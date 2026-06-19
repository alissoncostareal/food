<?php

namespace Tests\Unit;

use App\Support\OutboundMail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundMailTest extends TestCase
{
    #[Test]
    public function smtp_is_not_configured_without_host_and_credentials(): void
    {
        config(['mail.default' => 'smtp']);
        putenv('MAIL_HOST=');
        putenv('MAIL_USERNAME=');
        putenv('MAIL_PASSWORD=');
        putenv('MAIL_URL=');

        $this->assertFalse(OutboundMail::isConfigured());
    }

    #[Test]
    public function smtp_is_not_configured_with_localhost_host(): void
    {
        config(['mail.default' => 'smtp']);
        putenv('MAIL_HOST=127.0.0.1');
        putenv('MAIL_USERNAME=user');
        putenv('MAIL_PASSWORD=secret');
        putenv('MAIL_URL=');

        $this->assertFalse(OutboundMail::isConfigured());
    }

    #[Test]
    public function smtp_is_configured_with_brevo_credentials(): void
    {
        config(['mail.default' => 'smtp']);
        putenv('MAIL_HOST=smtp-relay.brevo.com');
        putenv('MAIL_USERNAME=noreply@partiumenu.com.br');
        putenv('MAIL_PASSWORD=xsmtpsib-test');
        putenv('MAIL_URL=');

        $this->assertTrue(OutboundMail::isConfigured());
    }
}
