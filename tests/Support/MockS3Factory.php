<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3\Tests\Support;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

final class MockS3Factory
{
    /**
     * @return array{S3ClientInterface, MockHandler, RecordedCommands}
     */
    public static function create(): array
    {
        $recorded = new RecordedCommands();
        $mock = new MockHandler();

        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'testing', 'secret' => 'testing'],
            'handler' => self::recordingHandler($mock, $recorded),
        ]);

        return [$client, $mock, $recorded];
    }

    private static function recordingHandler(MockHandler $mock, RecordedCommands $recorded): callable
    {
        return static function (CommandInterface $command, ?RequestInterface $request = null) use ($mock, $recorded): PromiseInterface {
            $recorded->commands[] = $command;

            return $mock($command, $request);
        };
    }

    /**
     * @return list<CommandInterface>
     */
    public static function commandsNamed(RecordedCommands $recorded, string $name): array
    {
        return array_values(array_filter(
            $recorded->commands,
            static fn (CommandInterface $command): bool => $command->getName() === $name
        ));
    }

    public static function firstCommandNamed(RecordedCommands $recorded, string $name): ?CommandInterface
    {
        $named = self::commandsNamed($recorded, $name);

        return $named[0] ?? null;
    }

    public static function putObjectOk(): Result
    {
        return new Result(['ETag' => '"etag"']);
    }

    public static function headObjectFile(array $overrides = []): Result
    {
        return new Result(array_merge([
            'ContentType' => 'text/plain',
            'ContentLength' => 12,
            'ETag' => '"abc123"',
        ], $overrides));
    }
}
