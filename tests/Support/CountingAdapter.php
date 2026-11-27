<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Override;

/**
 * An in-memory adapter that counts what a listing costs, and lists in a
 * chosen order: `strcmp` (like S3) or insertion order (like a local disk).
 *
 * Turned from the review's `listing.php` probe (review 1.0 #6, #7).
 *
 * @internal
 */
final class CountingAdapter implements FilesystemAdapter
{
    /** Listings asked for. */
    public int $calls = 0;

    /** File entries handed out by all listings together. */
    public int $files = 0;

    private readonly InMemoryFilesystemAdapter $inner;

    public function __construct(private readonly bool $sorted = false)
    {
        $this->inner = new InMemoryFilesystemAdapter();
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->files = 0;
    }

    #[Override]
    public function listContents(string $path, bool $deep): iterable
    {
        $this->calls++;
        $entries = iterator_to_array($this->inner->listContents($path, $deep), preserve_keys: false);
        if ($this->sorted) {
            usort($entries, static fn($a, $b): int => strcmp($a->path(), $b->path()));
        }

        foreach ($entries as $entry) {
            if ($entry instanceof FileAttributes) {
                $this->files++;
            }

            yield $entry;
        }
    }

    #[Override]
    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    #[Override]
    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    #[Override]
    public function write(string $path, string $contents, Config $config): void
    {
        $this->inner->write($path, $contents, $config);
    }

    #[Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->inner->writeStream($path, $contents, $config);
    }

    #[Override]
    public function read(string $path): string
    {
        return $this->inner->read($path);
    }

    #[Override]
    public function readStream(string $path)
    {
        return $this->inner->readStream($path);
    }

    #[Override]
    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    #[Override]
    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    #[Override]
    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    #[Override]
    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    #[Override]
    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    #[Override]
    public function mimeType(string $path): FileAttributes
    {
        return $this->inner->mimeType($path);
    }

    #[Override]
    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    #[Override]
    public function fileSize(string $path): FileAttributes
    {
        return $this->inner->fileSize($path);
    }

    #[Override]
    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    #[Override]
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
