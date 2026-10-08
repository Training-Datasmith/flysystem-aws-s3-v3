<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests\Support;

use Aws\CommandInterface;
use Aws\S3\S3Client;
use RuntimeException;

final class ThrowingPresignS3Client extends S3Client
{
    public function createPresignedRequest(CommandInterface $command, $expires, array $options = [])
    {
        throw new RuntimeException('presign failed');
    }
}
