<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests;

use Aws\Api\DateTimeResult;
use Aws\Result;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\Tests\Support\MockS3Factory;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AwsS3V3AdapterMetadataTest extends TestCase
{
    public function testMimeTypeFromHeadObject(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile(['ContentType' => 'text/plain']));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertSame('text/plain', $adapter->mimeType('a.txt')->mimeType());
    }

    public function testMimeTypeMissingThrows(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile(['ContentType' => null]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToRetrieveMetadata::class);
        $adapter->mimeType('a.txt');
    }

    public function testFileSizeFromContentLength(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile(['ContentLength' => 12]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertSame(12, $adapter->fileSize('a.txt')->fileSize());
    }

    public function testLastModifiedFromDateTimeResult(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $date = new DateTimeResult('2020-01-02T03:04:05+00:00');
        $mock->append(MockS3Factory::headObjectFile(['LastModified' => $date]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertSame($date->getTimestamp(), $adapter->lastModified('a.txt')->lastModified());
    }

    public function testExtraMetadataFieldsAreExposed(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile([
            'Metadata' => ['foo' => 'bar'],
            'StorageClass' => 'STANDARD',
            'VersionId' => 'v1',
        ]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $extra = $adapter->mimeType('a.txt')->extraMetadata();
        self::assertSame('bar', $extra['Metadata']['foo']);
        self::assertSame('STANDARD', $extra['StorageClass']);
        self::assertSame('v1', $extra['VersionId']);
        self::assertSame('"abc123"', $extra['ETag']);
    }

    public function testVisibilityPublicWhenAllUsersHaveRead(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new Result(['Grants' => [[
            'Grantee' => ['URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'],
            'Permission' => 'READ',
        ]]]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertSame(Visibility::PUBLIC, $adapter->visibility('a.txt')->visibility());
    }

    public function testVisibilityPrivateWithoutThatGrant(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new Result(['Grants' => []]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertSame(Visibility::PRIVATE, $adapter->visibility('a.txt')->visibility());
    }

    public function testHeadFailureThrowsUnableToRetrieveMetadata(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('head failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToRetrieveMetadata::class);
        $adapter->mimeType('a.txt');
    }

    public function testConstructorOptionsMergedIntoHeadObject(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, ['RequestPayer' => 'requester']);

        $adapter->fileSize('a.txt');

        $command = MockS3Factory::firstCommandNamed($recorded, 'HeadObject');
        self::assertSame('requester', $command['RequestPayer']);
    }
}
