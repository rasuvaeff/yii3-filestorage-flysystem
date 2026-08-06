<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Benchmarks;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageFlysystem\Stream\StreamWrapper;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Testo\Bench;

/**
 * What this package adds on top of Flysystem, with the network taken out.
 *
 * The adapter is in memory on purpose: against S3 every measurement would be
 * the round trip. What is left is the per-upload overhead this package is
 * responsible for — the stream wrapper — and the per-URL header mapping.
 *
 * @internal
 */
final class StoreBench
{
    private static ?Filesystem $filesystem = null;

    /**
     * The wrapper against the alternative it exists to avoid: copying the
     * upload into `php://temp` first. The gap is what one extra pass over the
     * body costs, and it is the reason the wrapper is worth ~120 lines.
     */
    #[Bench(
        callables: ['copy into php://temp' => [self::class, 'copyThenWrite']],
        calls: 200,
        iterations: 10,
    )]
    public static function writeThroughTheWrapper(): void
    {
        self::filesystem()->writeStream('bench/wrapped', StreamWrapper::wrap(self::upload()->stream()));
    }

    public static function copyThenWrite(): void
    {
        $temp = fopen('php://temp', 'w+b');
        \assert($temp !== false);
        stream_copy_to_stream(StreamWrapper::wrap(self::upload()->stream()), $temp);
        rewind($temp);
        self::filesystem()->writeStream('bench/copied', $temp);
        fclose($temp);
    }

    /**
     * Runs once per presigned URL. Compared with a bare `sprintf`, so the cost
     * of RFC 6266 encoding is visible rather than assumed.
     */
    #[Bench(
        callables: ['a bare sprintf' => [self::class, 'naiveDisposition']],
        calls: 5_000,
        iterations: 10,
    )]
    public static function mapDeliveryOptions(): ?array
    {
        return (new S3TemporaryUrlOptions())->for(self::deliveryOptions());
    }

    public static function naiveDisposition(): string
    {
        return sprintf('attachment; filename="%s"', self::deliveryOptions()->downloadName);
    }

    private static function filesystem(): Filesystem
    {
        return self::$filesystem ??= new Filesystem(new InMemoryFilesystemAdapter());
    }

    private static function upload(): Upload
    {
        $factory = new Psr17Factory();

        return Upload::fromStream($factory->createStream(str_repeat('x', 65_536)), 'a.bin', $factory);
    }

    private static function deliveryOptions(): DeliveryOptions
    {
        return DeliveryOptions::fromFile(
            file: File::create(
                id: 'bench',
                storeName: 'flysystem',
                groupName: 'common',
                relativePath: 'common/a/b/original.pdf',
                originalName: 'quarterly report.pdf',
                size: 1,
                createdAt: new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
                mimeType: 'application/pdf',
            ),
            policy: new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: true),
        );
    }
}
