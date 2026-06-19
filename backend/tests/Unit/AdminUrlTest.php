<?php

namespace Tests\Unit;

use App\Support\AdminUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminUrlTest extends TestCase
{
    #[Test]
    public function it_builds_invite_url_from_admin_dashboard_config(): void
    {
        config(['services.admin.url' => 'https://admin.partiumenu.com.br']);

        $this->assertSame(
            'https://admin.partiumenu.com.br/convite/abc123',
            AdminUrl::invite('abc123')
        );
    }

    #[Test]
    public function it_ignores_localhost_outside_local_environment(): void
    {
        config(['services.admin.url' => 'http://localhost:5175']);
        config(['app.env' => 'production']);

        $this->expectException(\RuntimeException::class);

        AdminUrl::base();
    }
}
