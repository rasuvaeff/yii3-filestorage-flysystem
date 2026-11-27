<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem;

use DateTimeImmutable;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageFlysystem\Stream\StreamWrapper;

/**
 * {@see FlysystemStore} plus the one capability deduplication needs.
 *
 * A separate class rather than a flag, because the difference is not a
 * preference: an adapter that publishes non-atomically will hand a second
 * writer a half-uploaded object and call it a cache hit. Which adapters those
 * are is not discoverable from PHP, so {@see AdapterSemantics} makes the
 * application say it out loud, and the constructor will not accept anything
 * less. An installation that cannot make that promise keeps the plain
 * `FlysystemStore` binding and stays unique-only — fully functional, just not
 * deduplicating.
 *
 * Everything except {@see putIfAbsent()} is the base store's behaviour,
 * delegated rather than inherited so the base can stay `final`.
 *
 * @api
 */
final readonly class FlysystemContentAddressableStore implements
    ContentAddressableStoreInterface,
    MaintenanceStoreInterface,
    StoreUrlProviderInterface
{
    private const int MEASURE_CHUNK = 262_144;

    public function __construct(
        private FlysystemStore $store,
        private FilesystemOperator $filesystem,
        private AdapterSemantics $semantics,
    ) {}

    /**
     * Counts an upload that does not report its own length.
     *
     * @return int<0, max>
     */
    private function measure(Upload $upload): int
    {
        $stream = $upload->stream();
        $counted = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(self::MEASURE_CHUNK);
            if ($chunk === '') {
                break;
            }

            $counted += \strlen($chunk);
        }
        $stream->rewind();

        return $counted;
    }

    /**
     * Publishes immutable bytes at a content-addressed key, or reuses them.
     *
     * The reuse check is a size comparison, not a re-read: the key already
     * *is* the hash of the content, so bytes at that key with a matching length
     * are the same bytes unless SHA-256 has been broken. A length that
     * disagrees means the key was written by something that does not share this
     * convention — which invalidates the whole premise, so it is a hard error
     * rather than a silent overwrite or a silent reuse.
     *
     * The `fileExists()`-then-`write()` race exists, and is harmless here: two
     * writers arriving together both see the key absent, both write the same
     * bytes to it, and {@see AdapterSemantics::$atomicVisibility} is the
     * promise that a reader in between sees one whole object or none. Both
     * then report `created: true`. That flag is advisory — it may be true for
     * more than one concurrent writer — and nothing may delete bytes on its
     * basis: the ledger's references and the collector own that decision. An
     * exclusive first write (S3 `If-None-Match: *`) is not reachable through
     * Flysystem 3's write config, and would hold for one adapter only.
     */
    #[Override]
    public function putIfAbsent(
        Upload $upload,
        StoredObjectId $object,
        int $maxBytes = 0,
    ): StoreResult {
        $path = $object->relativePath;
        $declared = $upload->size();

        if ($this->store->isQuarantined($path)) {
            throw new StoreException(
                "Content-addressed key \"{$path}\" lies inside the quarantine of store \"{$this->name()}\"",
            );
        }

        if ($maxBytes > 0 && $declared !== null && $declared > $maxBytes) {
            throw new UploadTooLargeException(
                "Upload of {$declared} bytes exceeds the {$maxBytes} byte limit",
            );
        }

        try {
            if ($this->filesystem->fileExists($path)) {
                $existing = max(0, $this->filesystem->fileSize($path));

                // Counted when the body does not declare a length, rather than
                // skipped. The contract says bytes are reused only after a size
                // check, and "the upload did not say" is the ordinary case for
                // a stream — so skipping it made the guarantee vacuous exactly
                // where it was needed. Counting is a local read of a rewound,
                // seekable stream; this branch transfers nothing, so it stays
                // far cheaper than the upload it avoids.
                $actual = $declared ?? $this->measure($upload);

                if ($existing !== $actual) {
                    throw new StoreException(
                        "Content-addressed object \"{$path}\" holds {$existing} bytes but the upload is "
                        . "{$actual}. Something outside this package wrote that key",
                    );
                }

                if ($maxBytes > 0 && $existing > $maxBytes) {
                    // The cap is about what this caller may store, not about
                    // how the bytes got there. Reusing past it would let a
                    // group's limit be bypassed by anything that uploaded the
                    // same content under a laxer one.
                    throw new UploadTooLargeException(
                        "Upload of {$existing} bytes exceeds the {$maxBytes} byte limit",
                    );
                }

                return new StoreResult(
                    relativePath: $path,
                    size: $existing,
                    externalId: $path,
                    created: false,
                );
            }

            $this->filesystem->writeStream($path, StreamWrapper::wrap($upload->stream()));
            $written = max(0, $this->filesystem->fileSize($path));
        } catch (FilesystemException $e) {
            throw new StoreException("Could not write \"{$path}\" to store \"{$this->name()}\"", 0, $e);
        }

        if ($maxBytes > 0 && $written > $maxBytes) {
            // Unlike a unique write, this object may already be referenced by a
            // writer that got here first, so removing it is not ours to do. The
            // ledger's grace period and collection pass reclaim it if nothing
            // commits against it.
            throw new UploadTooLargeException(
                "Upload of {$written} bytes exceeds the {$maxBytes} byte limit",
            );
        }

        return new StoreResult(relativePath: $path, size: $written, externalId: $path);
    }

    /**
     * The semantics this store was configured with, for diagnostics such as
     * `filestorage:check`.
     */
    public function semantics(): AdapterSemantics
    {
        return $this->semantics;
    }

    #[Override]
    public function name(): string
    {
        return $this->store->name();
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        return $this->store->write($upload, $groupName, $pathGenerator, $mediaType, $maxBytes);
    }

    #[Override]
    public function layout(): ObjectLayoutInterface
    {
        return $this->store->layout();
    }

    #[Override]
    public function delete(File $file): void
    {
        $this->store->delete($file);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->store->exists($file);
    }

    #[Override]
    public function size(File $file): ?int
    {
        return $this->store->size($file);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->store->lastModified($file);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->store->stream($file);
    }

    #[Override]
    public function publicUrl(File $file): ?string
    {
        return $this->store->publicUrl($file);
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt, DeliveryOptions $options): ?string
    {
        return $this->store->temporaryUrl($file, $expiresAt, $options);
    }

    #[Override]
    public function objects(
        ?string $afterPath = null,
        int $limit = 1000,
    ): iterable {
        return $this->store->objects($afterPath, $limit);
    }

    #[Override]
    public function deleteObject(StoredObjectId $object): void
    {
        $this->store->deleteObject($object);
    }

    #[Override]
    public function quarantine(ObjectSet $objects, DateTimeImmutable $now): ?QuarantinedObject
    {
        return $this->store->quarantine($objects, $now);
    }

    #[Override]
    public function restore(QuarantinedObject $entry): void
    {
        $this->store->restore($entry);
    }

    #[Override]
    public function purge(QuarantinedObject $entry): void
    {
        $this->store->purge($entry);
    }

    #[Override]
    public function quarantined(
        ?string $afterToken = null,
        int $limit = 1000,
    ): iterable {
        return $this->store->quarantined($afterToken, $limit);
    }

    #[Override]
    public function quarantineProblem(): ?string
    {
        return $this->store->quarantineProblem();
    }
}
