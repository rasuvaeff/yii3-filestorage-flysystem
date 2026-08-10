<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * @internal
 */
final class Fixtures
{
    public static function filesystem(): Filesystem
    {
        return new Filesystem(new InMemoryFilesystemAdapter());
    }

    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    public static function upload(string $contents = 'hello', string $name = 'a.txt'): Upload
    {
        $factory = self::factory();

        return Upload::fromStream($factory->createStream($contents), $name, $factory);
    }

    public static function file(
        string $relativePath = 'common/ab/cd/key/original.txt',
        string $storeName = 'flysystem',
        int $size = 5,
    ): File {
        return File::create(
            id: 'file-1',
            storeName: $storeName,
            groupName: 'common',
            relativePath: $relativePath,
            originalName: 'a.txt',
            size: $size,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
            mimeType: 'text/plain',
        );
    }

    public static function deliveryOptions(bool $forceDownload = true): DeliveryOptions
    {
        return DeliveryOptions::fromFile(
            file: self::file(),
            policy: new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: $forceDownload),
        );
    }
}
