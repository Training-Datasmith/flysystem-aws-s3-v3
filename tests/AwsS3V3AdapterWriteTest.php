<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests;

use Aws\Result;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\Tests\Support\FixedMimeTypeDetector;
use League\Flysystem\AwsS3V3\Tests\Support\MockS3Factory;
use League\Flysystem\AwsS3V3\Tests\Support\ThrowingMimeTypeDetector;
use League\Flysystem\Config;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AwsS3V3AdapterWriteTest extends TestCase
{
    public function testWritePutsObjectWithBucketPrefixAndPrivateAcl(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->write('a.txt', 'hello', new Config());

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertNotNull($command);
        self::assertSame('test-bucket', $command['Bucket']);
        self::assertSame('root/a.txt', $command['Key']);
        self::assertSame('private', $command['ACL']);
        self::assertSame('hello', (string) $command['Body']);
    }

    public function testLeadingSlashIsStrippedFromKey(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->write('/a.txt', 'hello', new Config());

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('root/a.txt', $command['Key']);
    }

    public function testWritePublicVisibilityUsesPublicReadAcl(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->write('a.txt', 'hello', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('public-read', $command['ACL']);
    }

    public function testWriteAclOptionOverridesVisibility(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->write('a.txt', 'hello', new Config([
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
            'ACL' => 'bucket-owner-full-control',
        ]));

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('bucket-owner-full-control', $command['ACL']);
    }

    public function testWriteExplicitContentTypeSkipsDetector(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, new ThrowingMimeTypeDetector());

        $adapter->write('a.txt', 'hello', new Config(['ContentType' => 'text/plain+special']));

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('text/plain+special', $command['ContentType']);
    }

    public function testWriteMimetypeKeySetsContentType(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, new ThrowingMimeTypeDetector());

        $adapter->write('a.txt', 'hello', new Config(['mimetype' => 'text/plain+special']));

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('text/plain+special', $command['ContentType']);
    }

    public function testWriteContentTypeWinsOverMimetypeKey(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, new ThrowingMimeTypeDetector());

        $adapter->write('a.txt', 'hello', new Config([
            'mimetype' => 'text/plain',
            'ContentType' => 'text/plain+special',
        ]));

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('text/plain+special', $command['ContentType']);
    }

    public function testWriteDetectsMimeTypeWhenOmitted(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, new FixedMimeTypeDetector('application/x-test'));

        $adapter->write('notes.txt', 'hello', new Config());

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('application/x-test', $command['ContentType']);
    }

    public function testWriteForwardsSupportedObjectOptions(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->write('a.txt', 'hello', new Config([
            'CacheControl' => 'max-age=60',
            'Metadata' => ['foo' => 'bar'],
            'StorageClass' => 'STANDARD',
        ]));

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('max-age=60', $command['CacheControl']);
        self::assertSame(['foo' => 'bar'], $command['Metadata']);
        self::assertSame('STANDARD', $command['StorageClass']);
    }

    public function testWriteAppliesConstructorOptionDefaults(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, ['CacheControl' => 'max-age=10']);

        $adapter->write('a.txt', 'hello', new Config());

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('max-age=10', $command['CacheControl']);
    }

    public function testWriteStreamUploadsStreamContents(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'stream-body');
        rewind($stream);

        $adapter->writeStream('a.txt', $stream, new Config());

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('stream-body', (string) $command['Body']);
        fclose($stream);
    }

    public function testMultipartThresholdSelectsMultipartUpload(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            new Result(['UploadId' => 'upload-id']),
            new Result(['ETag' => '"part-etag"']),
            new Result(['ETag' => '"final-etag"']),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->write('a.txt', 'hello', new Config(['mup_threshold' => 1]));

        self::assertNotNull(MockS3Factory::firstCommandNamed($recorded, 'CreateMultipartUpload'));
        self::assertNotNull(MockS3Factory::firstCommandNamed($recorded, 'UploadPart'));
        self::assertNotNull(MockS3Factory::firstCommandNamed($recorded, 'CompleteMultipartUpload'));
    }

    public function testCreateDirectoryWritesEmptyMarkerWithDefaultPublicAcl(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->createDirectory('dir', new Config());

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('root/dir/', $command['Key']);
        self::assertSame('', (string) $command['Body']);
        self::assertSame('public-read', $command['ACL']);
    }

    public function testCreateDirectoryUsesDirectoryVisibility(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::putObjectOk());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->createDirectory('dir', new Config([Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PRIVATE]));

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObject');
        self::assertSame('private', $command['ACL']);
    }

    public function testWriteFailureIncludesUnderlyingMessage(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('disk full'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        try {
            $adapter->write('a.txt', 'hello', new Config());
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            self::assertStringContainsString('disk full', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }
}
