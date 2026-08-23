<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use InvalidArgumentException;
use NStructure\Application\Storage\AssetImageStorage;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

final class AssetImageStorageTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nstructure-image-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0770, true);
    }

    protected function tearDown(): void
    {
        $storageDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'storage';
        foreach (glob($storageDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($storageDirectory)) {
            rmdir($storageDirectory);
        }
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->temporaryDirectory);
    }

    public function testValidImageIsInspectedAndStoredWithAnOpaqueName(): void
    {
        $source = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'source.png';
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $upload = new UploadedFile($source, 'rack-front.png', 'application/octet-stream', filesize($source));
        $storage = new AssetImageStorage($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'storage');

        $metadata = $storage->store($upload);

        self::assertSame('image/png', $metadata['mime_type']);
        self::assertSame('rack-front.png', $metadata['original_name']);
        self::assertSame(1, $metadata['width_px']);
        self::assertSame(1, $metadata['height_px']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{40}\.png\z/', $metadata['storage_path']);
        self::assertFileExists($storage->path($metadata['storage_path']));
    }

    public function testClientMimeTypeCannotDisguiseANonImageFile(): void
    {
        $source = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'invalid.jpg';
        file_put_contents($source, '<?php echo "not an image";');
        $upload = new UploadedFile($source, 'invalid.jpg', 'image/jpeg', filesize($source));
        $storage = new AssetImageStorage($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'storage');

        $this->expectException(InvalidArgumentException::class);
        $storage->store($upload);
    }
}
