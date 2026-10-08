<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests;

use Aws\Result;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\Tests\Support\MockS3Factory;
use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AwsS3V3AdapterCopyMoveTest extends TestCase
{
    public function testCopySamePathSendsNoCommands(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->copy('a.txt', 'a.txt', new Config());

        self::assertSame([], $recorded->commands);
    }

    public function testCopyWithExplicitVisibilitySkipsAclLookup(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->copy('from.txt', 'to.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertNull(MockS3Factory::firstCommandNamed($recorded, 'GetObjectAcl'));
        $copy = MockS3Factory::firstCommandNamed($recorded, 'CopyObject');
        self::assertSame('private', $copy['ACL']);
    }

    public function testCopyRetainsVisibilityFromGetObjectAclByDefault(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            new Result(['Grants' => [[
                'Grantee' => ['URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'],
                'Permission' => 'READ',
            ]]]),
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->copy('from.txt', 'to.txt', new Config());

        $acl = MockS3Factory::firstCommandNamed($recorded, 'GetObjectAcl');
        self::assertNotNull($acl);
        self::assertSame('root/from.txt', $acl['Key']);

        $copy = MockS3Factory::firstCommandNamed($recorded, 'CopyObject');
        self::assertSame('public-read', $copy['ACL']);
    }

    public function testCopyAclOnlyWithDefaultRetainVisibilitySkipsAclLookup(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->copy('from.txt', 'to.txt', new Config(['ACL' => 'public-read']));

        self::assertNull(MockS3Factory::firstCommandNamed($recorded, 'GetObjectAcl'));
        $copy = MockS3Factory::firstCommandNamed($recorded, 'CopyObject');
        self::assertSame('public-read', $copy['ACL']);
    }

    public function testCopyConstructorDefaultAclSkipsAclLookup(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, ['ACL' => 'bucket-owner-full-control']);

        $adapter->copy('from.txt', 'to.txt', new Config());

        self::assertNull(MockS3Factory::firstCommandNamed($recorded, 'GetObjectAcl'));
        $copy = MockS3Factory::firstCommandNamed($recorded, 'CopyObject');
        self::assertSame('bucket-owner-full-control', $copy['ACL']);
    }

    public function testCopyHonorsAdapterLevelMetadataDirective(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, ['MetadataDirective' => 'REPLACE']);

        $adapter->copy('from.txt', 'to.txt', new Config(['retain_visibility' => false]));

        $copy = MockS3Factory::firstCommandNamed($recorded, 'CopyObject');
        self::assertSame('REPLACE', $copy['MetadataDirective']);
    }

    public function testCopyHonorsPerCallMetadataDirectiveWithCustomForwardedOptions(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root', null, null, [], true, ['ContentType']);

        $adapter->copy('from.txt', 'to.txt', new Config([
            'MetadataDirective' => 'REPLACE',
            'retain_visibility' => false,
        ]));

        $copy = MockS3Factory::firstCommandNamed($recorded, 'CopyObject');
        self::assertSame('REPLACE', $copy['MetadataDirective']);
    }

    public function testCopyPrefixesKeysAndCopySource(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->copy('from.txt', 'to.txt', new Config(['retain_visibility' => false]));

        $head = MockS3Factory::firstCommandNamed($recorded, 'HeadObject');
        self::assertSame('root/from.txt', $head['Key']);

        $copy = MockS3Factory::firstCommandNamed($recorded, 'CopyObject');
        self::assertSame('root/to.txt', $copy['Key']);
        self::assertStringContainsString('test-bucket', $copy['CopySource']);
        self::assertStringContainsString('from.txt', $copy['CopySource']);
    }

    public function testCopyAclLookupFailureThrowsUnableToCopyFile(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(new RuntimeException('acl failed'));
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $this->expectException(UnableToCopyFile::class);
        $adapter->copy('from.txt', 'to.txt', new Config());
    }

    public function testMoveCopiesThenDeletes(): void
    {
        [$client, $mock, $recorded] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
            new Result([]),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        $adapter->move('from.txt', 'to.txt', new Config(['retain_visibility' => false]));

        self::assertNotNull(MockS3Factory::firstCommandNamed($recorded, 'CopyObject'));
        $delete = MockS3Factory::firstCommandNamed($recorded, 'DeleteObject');
        self::assertSame('root/from.txt', $delete['Key']);
    }

    public function testMoveDeleteFailureThrowsUnableToMoveFile(): void
    {
        [$client, $mock] = MockS3Factory::create();
        $mock->append(
            MockS3Factory::headObjectFile(['ContentLength' => 4]),
            new Result([]),
            new RuntimeException('delete failed'),
        );
        $adapter = new AwsS3V3Adapter($client, 'test-bucket', 'root');

        try {
            $adapter->move('from.txt', 'to.txt', new Config(['retain_visibility' => false]));
            self::fail('Expected UnableToMoveFile');
        } catch (UnableToMoveFile $exception) {
            self::assertInstanceOf(UnableToDeleteFile::class, $exception->getPrevious());
        }
    }
}
