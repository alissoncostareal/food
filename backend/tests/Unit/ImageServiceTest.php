<?php

namespace Tests\Unit;

use App\Services\ImageService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageServiceTest extends TestCase
{
    #[Test]
    public function it_converts_webp_to_jpeg_data_uri_for_ifood(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        $webp = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAQAcJaQAA3AA/vuUAAA=');
        $path = 'products/test.webp';
        Storage::disk('public')->put($path, $webp);

        $dataUri = ImageService::toDataUri($path);

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUri);
    }

    #[Test]
    public function it_strips_bucket_prefix_from_path_style_storage_urls(): void
    {
        config(['filesystems.disks.s3.bucket' => 'partiumenu-media']);

        $this->assertSame(
            'products/abc.jpg',
            ImageService::extractStoragePath('https://example.r2.dev/partiumenu-media/products/abc.jpg')
        );
    }

    #[Test]
    public function it_reads_image_from_public_url_when_disk_path_is_missing(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $url = 'https://cdn.example.test/products/missing-on-disk.png';

        \Illuminate\Support\Facades\Http::fake([
            $url => \Illuminate\Support\Facades\Http::response($png, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $dataUri = ImageService::toDataUri($url);

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    #[Test]
    public function it_rejects_invalid_binary_when_building_data_uri(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        Storage::disk('public')->put('products/not-image.txt', 'not an image');

        $this->assertNull(ImageService::toDataUri('products/not-image.txt'));
    }

    #[Test]
    public function it_resolves_relative_storage_paths(): void
    {
        $this->assertSame('products/abc.jpg', ImageService::resolveStoredPath('products/abc.jpg'));
    }
}
