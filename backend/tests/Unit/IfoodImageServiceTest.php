<?php

namespace Tests\Unit;

use App\Services\IfoodImageService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IfoodImageServiceTest extends TestCase
{
    #[Test]
    public function it_normalizes_cdn_urls_to_catalog_image_paths(): void
    {
        $path = IfoodImageService::normalizeUploadPath(
            'https://static-images.ifood.com.br/image/upload/t_medium/pratos/7a27e4ac-c370-4adb-b395-397f503386cc/202311161149_3y9mdsnr8a9.png'
        );

        $this->assertSame(
            '7a27e4ac-c370-4adb-b395-397f503386cc/202311161149_3y9mdsnr8a9.png',
            $path
        );
    }

    #[Test]
    public function it_extracts_valid_upload_paths_from_json_responses(): void
    {
        $service = app(IfoodImageService::class);

        $path = $service->extractUploadPath([
            'imagePath' => '7a27e4ac-c370-4adb-b395-397f503386cc/202311161149_3y9mdsnr8a9.png',
        ]);

        $this->assertSame(
            '7a27e4ac-c370-4adb-b395-397f503386cc/202311161149_3y9mdsnr8a9.png',
            $path
        );
    }

    #[Test]
    public function it_normalizes_full_cdn_urls_from_upload_json(): void
    {
        $service = app(IfoodImageService::class);

        $path = $service->extractUploadPath([
            'url' => 'https://static-images.ifood.com.br/image/upload/t_medium/pratos/abc/def.png',
        ]);

        $this->assertSame('abc/def.png', $path);
    }
}
