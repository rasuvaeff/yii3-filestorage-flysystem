<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToReadFile;
use Override;

/**
 * A store that cannot answer at all — a reset connection, an expired
 * credential, a throttling response.
 *
 * Flysystem reports "no such key" and "the request failed" with the same
 * exception type, so the only double that tells the two apart is one where the
 * follow-up existence probe fails too.
 *
 * Only the methods under test are implemented; the rest are unreachable and say
 * so rather than pretending to work.
 *
 * @internal
 */
final readonly class UnreachableFilesystem implements FilesystemOperator
{
    /**
     * @param bool $existenceAnswers When true the probe succeeds and reports the
     *        object as present — a store that can be reached but cannot serve
     *        the bytes, which is a different fault from being unreachable.
     */
    public function __construct(private bool $existenceAnswers = false) {}

    #[Override]
    public function readStream(string $location)
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function fileExists(string $location): bool
    {
        if ($this->existenceAnswers) {
            return true;
        }

        throw UnableToCheckExistence::forLocation($location);
    }

    #[Override]
    public function directoryExists(string $location): bool
    {
        throw UnableToCheckExistence::forLocation($location);
    }

    #[Override]
    public function has(string $location): bool
    {
        throw UnableToCheckExistence::forLocation($location);
    }

    #[Override]
    public function read(string $location): string
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): \League\Flysystem\DirectoryListing
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function lastModified(string $path): int
    {
        throw UnableToReadFile::fromLocation($path, 'connection reset');
    }

    #[Override]
    public function fileSize(string $path): int
    {
        throw UnableToReadFile::fromLocation($path, 'connection reset');
    }

    #[Override]
    public function mimeType(string $path): string
    {
        throw UnableToReadFile::fromLocation($path, 'connection reset');
    }

    #[Override]
    public function visibility(string $path): string
    {
        throw UnableToReadFile::fromLocation($path, 'connection reset');
    }

    #[Override]
    public function write(string $location, string $contents, array $config = []): void
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function writeStream(string $location, $contents, array $config = []): void
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToReadFile::fromLocation($path, 'connection reset');
    }

    #[Override]
    public function delete(string $location): void
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function deleteDirectory(string $location): void
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function createDirectory(string $location, array $config = []): void
    {
        throw UnableToReadFile::fromLocation($location, 'connection reset');
    }

    #[Override]
    public function move(string $source, string $destination, array $config = []): void
    {
        throw UnableToReadFile::fromLocation($source, 'connection reset');
    }

    #[Override]
    public function copy(string $source, string $destination, array $config = []): void
    {
        throw UnableToReadFile::fromLocation($source, 'connection reset');
    }
}
