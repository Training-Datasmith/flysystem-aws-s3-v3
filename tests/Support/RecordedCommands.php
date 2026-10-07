<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests\Support;

use Aws\CommandInterface;

final class RecordedCommands
{
    /**
     * @var list<CommandInterface>
     */
    public array $commands = [];
}
