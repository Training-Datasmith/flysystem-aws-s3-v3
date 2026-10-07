<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests;

use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

final class PortableVisibilityConverterTest extends TestCase
{
    public function testPublicMapsToPublicRead(): void
    {
        $converter = new PortableVisibilityConverter();

        self::assertSame('public-read', $converter->visibilityToAcl(Visibility::PUBLIC));
    }

    public function testPrivateMapsToPrivate(): void
    {
        $converter = new PortableVisibilityConverter();

        self::assertSame('private', $converter->visibilityToAcl(Visibility::PRIVATE));
    }

    public function testUnknownVisibilityMapsToPrivate(): void
    {
        $converter = new PortableVisibilityConverter();

        self::assertSame('private', $converter->visibilityToAcl('custom'));
    }

    public function testAllUsersReadGrantIsPublic(): void
    {
        $converter = new PortableVisibilityConverter();
        $grants = [[
            'Grantee' => ['URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'],
            'Permission' => 'READ',
        ]];

        self::assertSame(Visibility::PUBLIC, $converter->aclToVisibility($grants));
    }

    public function testAllUsersWriteIsPrivate(): void
    {
        $converter = new PortableVisibilityConverter();
        $grants = [[
            'Grantee' => ['URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'],
            'Permission' => 'WRITE',
        ]];

        self::assertSame(Visibility::PRIVATE, $converter->aclToVisibility($grants));
    }

    public function testEmptyGrantsArePrivate(): void
    {
        $converter = new PortableVisibilityConverter();

        self::assertSame(Visibility::PRIVATE, $converter->aclToVisibility([]));
    }

    public function testGrantMissingGranteeIsPrivate(): void
    {
        $converter = new PortableVisibilityConverter();

        self::assertSame(Visibility::PRIVATE, $converter->aclToVisibility([['Permission' => 'READ']]));
    }

    public function testDefaultDirectoryVisibilityIsPublic(): void
    {
        $converter = new PortableVisibilityConverter();

        self::assertSame(Visibility::PUBLIC, $converter->defaultForDirectories());
    }

    public function testCustomDefaultDirectoryVisibility(): void
    {
        $converter = new PortableVisibilityConverter(Visibility::PRIVATE);

        self::assertSame(Visibility::PRIVATE, $converter->defaultForDirectories());
    }
}
