<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests\Support;

use League\MimeTypeDetection\MimeTypeDetector;
use RuntimeException;

final class ThrowingMimeTypeDetector implements MimeTypeDetector
{
    public function detectMimeType(string $path, $contents): ?string
    {
        throw new RuntimeException('Mime detection should not run when ContentType is explicit.');
    }

    public function detectMimeTypeFromPath(string $path): ?string
    {
        throw new RuntimeException('Mime detection should not run when ContentType is explicit.');
    }

    public function detectMimeTypeFromFile(string $path): ?string
    {
        throw new RuntimeException('Mime detection should not run when ContentType is explicit.');
    }

    public function detectMimeTypeFromBuffer(string $contents): ?string
    {
        throw new RuntimeException('Mime detection should not run when ContentType is explicit.');
    }
}
