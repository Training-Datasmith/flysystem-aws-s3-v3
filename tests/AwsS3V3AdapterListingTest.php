<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests;

use Aws\Result;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\Tests\Support\MockS3Factory;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToListContents;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AwsS3V3AdapterListingTest extends TestCase
{
    public function testDirectoryExistsTrueWhenContentsPresent(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new Result(['Contents' => [['Key' => 'root/dir/file.txt']]]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertTrue($adapter->directoryExists('dir'));
    }

    public function testDirectoryExistsFalseWhenNeitherKeyPresent(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new Result([]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertFalse($adapter->directoryExists('dir'));
    }

    public function testDirectoryExistsForwardsRequestPayer(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(new Result([]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, ['RequestPayer' => 'requester']);

        $adapter->directoryExists('dir');

        $command = MockS3Factory::firstCommandNamed($recorded, 'ListObjectsV2');
        self::assertSame('requester', $command['RequestPayer']);
    }

    public function testShallowListReturnsRelativeFilesAndDirectories(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new Result([
            'CommonPrefixes' => [['Prefix' => 'root/sub/']],
            'Contents' => [['Key' => 'root/a.txt', 'Size' => 3]],
        ]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $items = iterator_to_array($adapter->listContents('', false));
        usort($items, static fn ($a, $b) => strcmp($a->path(), $b->path()));

        self::assertCount(2, $items);
        self::assertInstanceOf(FileAttributes::class, $items[0]);
        self::assertInstanceOf(DirectoryAttributes::class, $items[1]);
        self::assertSame('a.txt', $items[0]->path());
        self::assertSame('sub', $items[1]->path());
    }

    public function testListFollowsPaginatorToken(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            new Result([
                'IsTruncated' => true,
                'NextContinuationToken' => 'tok',
                'Contents' => [['Key' => 'root/a.txt', 'Size' => 1]],
            ]),
            new Result([
                'Contents' => [['Key' => 'root/b.txt', 'Size' => 1]],
            ]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $paths = array_map(
            static fn ($item) => $item->path(),
            iterator_to_array($adapter->listContents('', true))
        );
        sort($paths);

        self::assertSame(['a.txt', 'b.txt'], $paths);

        $listCommands = MockS3Factory::commandsNamed($recorded, 'ListObjectsV2');
        self::assertCount(2, $listCommands);
        self::assertSame('tok', $listCommands[1]['ContinuationToken']);
    }

    public function testFilesystemWrapsListFailuresAsUnableToListContents(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('list failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');
        $filesystem = new Filesystem($adapter);

        $this->expectException(UnableToListContents::class);
        iterator_to_array($filesystem->listContents('', false));
    }
}
