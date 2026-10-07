<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests;

use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\Tests\Support\MockS3Factory;
use League\Flysystem\Config;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AwsS3V3AdapterReadDeleteTest extends TestCase
{
    public function testReadReturnsBodyAndDoesNotForceHttpStream(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'hello');
        rewind($stream);
        $mock->append(new Result(['Body' => new Stream($stream)]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertSame('hello', $adapter->read('a.txt'));

        $command = MockS3Factory::firstCommandNamed($recorded, 'GetObject');
        self::assertSame('root/a.txt', $command['Key']);
        self::assertArrayNotHasKey('stream', $command['@http'] ?? []);
    }

    public function testReadStreamSetsHttpStreamWhenEnabled(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'hello');
        rewind($stream);
        $mock->append(new Result(['Body' => new Stream($stream)]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $resource = $adapter->readStream('a.txt');
        self::assertIsResource($resource);
        self::assertSame('hello', stream_get_contents($resource));
        fclose($resource);

        $command = MockS3Factory::firstCommandNamed($recorded, 'GetObject');
        self::assertTrue($command['@http']['stream']);
    }

    public function testReadStreamPreservesOtherHttpOptions(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'hello');
        rewind($stream);
        $mock->append(new Result(['Body' => new Stream($stream)]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, ['@http' => ['timeout' => 10]], true);

        $resource = $adapter->readStream('a.txt');
        fclose($resource);

        $command = MockS3Factory::firstCommandNamed($recorded, 'GetObject');
        self::assertSame(10, $command['@http']['timeout']);
        self::assertTrue($command['@http']['stream']);
    }

    public function testReadFailureThrowsUnableToReadFile(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('read failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToReadFile::class);
        $adapter->read('a.txt');
    }

    public function testFileExistsTrueOnHeadSuccess(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertTrue($adapter->fileExists('a.txt'));
        self::assertNotNull(MockS3Factory::firstCommandNamed($recorded, 'HeadObject'));
    }

    public function testFileExistsFalseOn404(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new S3Exception('Not Found', new Command('HeadObject'), ['response' => new Response(404)]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        self::assertFalse($adapter->fileExists('a.txt'));
    }

    public function testFileExistsWrapsServerError(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new S3Exception('error', new Command('HeadObject'), ['response' => new Response(500)]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToCheckFileExistence::class);
        $adapter->fileExists('a.txt');
    }

    public function testFileExistsForwardsConstructorOptions(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(MockS3Factory::headObjectFile());
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, ['RequestPayer' => 'requester']);

        $adapter->fileExists('a.txt');

        $command = MockS3Factory::firstCommandNamed($recorded, 'HeadObject');
        self::assertSame('requester', $command['RequestPayer']);
    }

    public function testDeleteSendsDeleteObject(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(new Result([]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->delete('a.txt');

        $command = MockS3Factory::firstCommandNamed($recorded, 'DeleteObject');
        self::assertSame('test-bucket', $command['Bucket']);
        self::assertSame('root/a.txt', $command['Key']);
    }

    public function testDeleteFailureThrowsUnableToDeleteFile(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('delete failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToDeleteFile::class);
        $adapter->delete('a.txt');
    }

    public function testDeleteDirectoryListsAndBatchDeletes(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            new Result(['Contents' => [
                ['Key' => 'root/dir/a.txt'],
                ['Key' => 'root/dir/b.txt'],
            ]]),
            new Result(['Deleted' => [['Key' => 'root/dir/a.txt'], ['Key' => 'root/dir/b.txt']]]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->deleteDirectory('dir');

        $list = MockS3Factory::firstCommandNamed($recorded, 'ListObjects');
        self::assertSame('root/dir/', $list['Prefix']);
        self::assertNotNull(MockS3Factory::firstCommandNamed($recorded, 'DeleteObjects'));
    }

    public function testDeleteDirectoryEmptyListingSucceeds(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(new Result([]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->deleteDirectory('dir');

        self::assertNull(MockS3Factory::firstCommandNamed($recorded, 'DeleteObjects'));
    }

    public function testDeleteDirectoryListFailureThrowsUnableToDeleteDirectory(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('list failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToDeleteDirectory::class);
        $adapter->deleteDirectory('dir');
    }

    public function testSetVisibilityPublicSendsPublicRead(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(new Result([]));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->setVisibility('a.txt', Visibility::PUBLIC);

        $command = MockS3Factory::firstCommandNamed($recorded, 'PutObjectAcl');
        self::assertSame('public-read', $command['ACL']);
        self::assertSame('root/a.txt', $command['Key']);
    }

    public function testSetVisibilityFailureThrowsUnableToSetVisibility(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('acl failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToSetVisibility::class);
        $adapter->setVisibility('a.txt', Visibility::PRIVATE);
    }
}
