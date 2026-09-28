<?php

namespace Tests\Unit;

use App\Support\OutboundMail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundMailTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_URL'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    #[Test]
    public function smtp_is_not_configured_without_host_and_credentials(): void
    {
        config(['mail.default' => 'smtp']);
        $this->setMailEnv([
            'MAIL_HOST' => '',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'MAIL_URL' => '',
        ]);

        $this->assertFalse(OutboundMail::isConfigured());
    }

    #[Test]
    public function smtp_is_not_configured_with_localhost_host(): void
    {
        config(['mail.default' => 'smtp']);
        $this->setMailEnv([
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_USERNAME' => 'user',
            'MAIL_PASSWORD' => 'secret',
            'MAIL_URL' => '',
        ]);

        $this->assertFalse(OutboundMail::isConfigured());
    }

    #[Test]
    public function smtp_is_configured_with_brevo_credentials(): void
    {
        config(['mail.default' => 'smtp']);
        $this->setMailEnv([
            'MAIL_HOST' => 'smtp-relay.brevo.com',
            'MAIL_USERNAME' => 'noreply@partiumenu.com.br',
            'MAIL_PASSWORD' => 'xsmtpsib-test',
            'MAIL_URL' => '',
        ]);

        $this->assertTrue(OutboundMail::isConfigured());
    }

    private function setMailEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
}
