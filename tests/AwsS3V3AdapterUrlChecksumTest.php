<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests;

use Aws\MockHandler;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\Tests\Support\MockS3Factory;
use League\Flysystem\AwsS3V3\Tests\Support\ThrowingGetObjectUrlS3Client;
use League\Flysystem\AwsS3V3\Tests\Support\ThrowingPresignS3Client;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\Config;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToRetrieveMetadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AwsS3V3AdapterUrlChecksumTest extends TestCase
{
    public function testPublicUrlContainsBucketAndPrefixedKey(): void
    {
        [$client] = MockS3Factory::create();
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $url = $adapter->publicUrl('a.txt', new Config());

        self::assertStringContainsString('test-bucket', $url);
        self::assertStringContainsString('root/a.txt', $url);
    }

    public function testPublicUrlFailureThrowsUnableToGeneratePublicUrl(): void
    {
        $client = new ThrowingGetObjectUrlS3Client([
            'service' => 's3',
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'testing', 'secret' => 'testing'],
            'handler' => new MockHandler(),
        ]);
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        try {
            $adapter->publicUrl('a.txt', new Config());
            self::fail('Expected UnableToGeneratePublicUrl');
        } catch (UnableToGeneratePublicUrl $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testTemporaryUrlIsPresignedForPrefixedKey(): void
    {
        [$client] = MockS3Factory::create();
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');
        $expires = new \DateTimeImmutable('+10 minutes');

        $url = $adapter->temporaryUrl('a.txt', $expires, new Config());

        self::assertStringContainsString('test-bucket', $url);
        self::assertStringContainsString('root/a.txt', $url);
        self::assertStringContainsString('X-Amz-Algorithm', $url);
        self::assertStringContainsString('X-Amz-Signature', $url);
    }

    public function testTemporaryUrlFailureThrowsUnableToGenerateTemporaryUrl(): void
    {
        $client = new ThrowingPresignS3Client([
            'service' => 's3',
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'testing', 'secret' => 'testing'],
            'handler' => new MockHandler(),
        ]);
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        try {
            $adapter->temporaryUrl('a.txt', new \DateTimeImmutable('+1 hour'), new Config());
            self::fail('Expected UnableToGenerateTemporaryUrl');
        } catch (UnableToGenerateTemporaryUrl $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testChecksumReturnsUnquotedEtag(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile(['ETag' => '"abc123"']));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertSame('abc123', $adapter->checksum('a.txt', new Config()));
    }

    public function testChecksumUnsupportedAlgoThrowsBeforeAnyCommand(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        try {
            $adapter->checksum('a.txt', new Config(['checksum_algo' => 'md5']));
            self::fail('Expected ChecksumAlgoIsNotSupported');
        } catch (ChecksumAlgoIsNotSupported $exception) {
            self::assertSame([], $recorded->commands);
        }
    }

    public function testChecksumMissingEtagThrowsUnableToProvideChecksum(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile(['ETag' => '']));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToProvideChecksum::class);
        $adapter->checksum('a.txt', new Config());
    }

    public function testChecksumHeadFailureThrowsUnableToProvideChecksum(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('head failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        try {
            $adapter->checksum('a.txt', new Config());
            self::fail('Expected UnableToProvideChecksum');
        } catch (UnableToProvideChecksum $exception) {
            self::assertInstanceOf(UnableToRetrieveMetadata::class, $exception->getPrevious());
        }
    }
}
