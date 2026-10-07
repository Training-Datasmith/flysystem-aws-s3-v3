<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests\Support;

use Aws\S3\S3Client;
use RuntimeException;

final class ThrowingGetObjectUrlS3Client extends S3Client
{
    public function getObjectUrl($bucket, $key)
    {
        throw new RuntimeException('url generation failed');
    }
}
