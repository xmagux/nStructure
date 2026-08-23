<?php

declare(strict_types=1);

namespace NStructure\Application\Storage;

use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final readonly class AssetImageStorage
{
    private const MAX_FILE_SIZE = 8 * 1024 * 1024;
    private const MAX_DIMENSION = 12000;
    private const MAX_PIXELS = 40_000_000;
    public function __construct(private string $directory)
    {
    }

    public function store(UploadedFileInterface $upload): array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage($upload->getError()));
        }

        $size = $upload->getSize();
        if ($size === null || $size < 1) {
            throw new InvalidArgumentException('The selected image is empty');
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('The image must not exceed 8 MB');
        }

        $stream = $upload->getStream();
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $contents = $stream->getContents();
        } finally {
            $stream->close();
        }
        $dimensions = @getimagesizefromstring($contents);
        if (!is_array($dimensions)) {
            throw new InvalidArgumentException('The uploaded file is not a valid image');
        }
        [$mimeType, $extension] = match ((int) ($dimensions[2] ?? 0)) {
            IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
            IMAGETYPE_PNG => ['image/png', 'png'],
            IMAGETYPE_WEBP => ['image/webp', 'webp'],
            default => throw new InvalidArgumentException('Only JPEG, PNG, and WebP images are supported'),
        };
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('The image dimensions are too large');
        }

        $this->ensureDirectory();
        $storagePath = bin2hex(random_bytes(20)) . '.' . $extension;
        $upload->moveTo($this->directory . DIRECTORY_SEPARATOR . $storagePath);

        return [
            'storage_path' => $storagePath,
            'original_name' => $this->safeOriginalName($upload->getClientFilename()),
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'width_px' => $width,
            'height_px' => $height,
        ];
    }

    public function path(string $storagePath): string
    {
        if (!preg_match('/\A[a-f0-9]{40}\.(?:jpg|png|webp)\z/', $storagePath)) {
            throw new RuntimeException('Invalid image storage path');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $storagePath;
        if (!is_file($path)) {
            throw new RuntimeException('Image file not found');
        }
        return $path;
    }

    public function delete(string $storagePath): void
    {
        if (!preg_match('/\A[a-f0-9]{40}\.(?:jpg|png|webp)\z/', $storagePath)) {
            return;
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $storagePath;
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            if (!is_writable($this->directory)) {
                throw new RuntimeException('Image storage is not writable');
            }
            return;
        }
        if (!mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Image storage could not be created');
        }
    }

    private function safeOriginalName(?string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', (string) $name)));
        return mb_substr($name !== '' ? $name : 'infrastructure-image', 0, 255);
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image exceeds the server upload limit',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted',
            UPLOAD_ERR_NO_FILE => 'Select an image to upload',
            default => 'The image could not be uploaded',
        };
    }
}
