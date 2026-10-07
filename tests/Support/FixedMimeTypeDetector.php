<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests\Support;

use League\MimeTypeDetection\MimeTypeDetector;

final class FixedMimeTypeDetector implements MimeTypeDetector
{
    public function __construct(private string $mimeType)
    {
    }

    public function detectMimeType(string $path, $contents): ?string
    {
        return $this->mimeType;
    }

    public function detectMimeTypeFromPath(string $path): ?string
    {
        return $this->mimeType;
    }

    public function detectMimeTypeFromFile(string $path): ?string
    {
        return $this->mimeType;
    }

    public function detectMimeTypeFromBuffer(string $contents): ?string
    {
        return $this->mimeType;
    }
}
