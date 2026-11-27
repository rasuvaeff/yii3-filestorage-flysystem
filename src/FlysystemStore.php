<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use Iterator;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageFlysystem\Listing\Continuation;
use Rasuvaeff\Yii3FilestorageFlysystem\Stream\StreamWrapper;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\TemporaryUrlOptionsInterface;

/**
 * Objects in anything Flysystem can address: S3, GCS, Azure, FTP, a ZIP file.
 *
 * One class for every adapter, with capabilities expressed as *results* rather
 * than as interfaces that appear and disappear. `publicUrl()` and
 * `temporaryUrl()` return null when the configured operator cannot produce one,
 * because a PHP interface cannot be implemented conditionally at runtime and a
 * single `FlysystemStore` has to wrap adapters that genuinely differ.
 *
 * Three things this store deliberately does *not* do:
 *
 * **No `RangeReadableStoreInterface`.** Flysystem has no range primitive —
 * `readStream()` returns the whole object — and `LimitedStream` requires a
 * seekable stream, which an S3 body is not. Implementing the interface by
 * reading and discarding a prefix would turn "seek to the last minute of a
 * video" into a full download while advertising the opposite. Where the
 * adapter *does* return a seekable stream, `rasuvaeff/yii3-filestorage-web`
 * already detects that and serves the range anyway; where it does not, a full
 * `200` is the correct answer. S3 presigned URLs handle ranges in S3 itself.
 *
 * **No `ContentAddressableStoreInterface`.** Sharing bytes between logical
 * files needs atomic publication and immutable keys, which Flysystem cannot
 * promise on the caller's behalf. {@see FlysystemContentAddressableStore} adds
 * it against an explicit {@see AdapterSemantics} declaration.
 *
 * **No mid-write byte cap.** A remote `PUT` cannot be aborted halfway from
 * here, so the cap is checked before the write and verified after it, with the
 * object removed on violation — see {@see write()}.
 *
 * Garbage collection deletes through a quarantine: a private prefix of the
 * same filesystem, reached with `move()`, that {@see objects()} never lists
 * and that no write may target. Without one, `filestorage:gc` cannot delete
 * from this store, and `filestorage:check` says so. On S3 a move is a copy and
 * a delete, not an atomic rename — see {@see restore()}.
 *
 * @api
 */
final readonly class FlysystemStore implements StoreUrlProviderInterface, MaintenanceStoreInterface
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';
    /**
     * The first segment starts with `.` or `_`, which no group or scope name
     * can (they start with a letter or digit), so no key a built-in generator
     * produces can fall inside the quarantine, nor the quarantine around one.
     */
    private const string QUARANTINE_PATTERN
        = '~^(?:_[A-Za-z0-9._-]*|\.[A-Za-z0-9_-][A-Za-z0-9._-]*)(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*\z~';

    /** @var non-empty-string */
    private string $name;

    /** @var non-empty-string|null */
    private ?string $quarantinePrefix;

    private ObjectLayoutInterface $layout;

    /** @var Continuation<StoredObject> */
    private Continuation $objectsListing;

    /** @var Continuation<QuarantinedObject> */
    private Continuation $quarantineListing;

    /**
     * @param TemporaryUrlOptionsInterface|null $temporaryUrlOptions Absent means
     *        no presigned URLs: without a mapping this store cannot promise the
     *        response headers a delivery policy asks for.
     * @param string|null $quarantinePrefix Where garbage collection parks
     *        objects before purging or restoring them, e.g. `.quarantine`. Its
     *        first segment starts with `.` or `_`. Must not be publicly
     *        readable: exclude it from any public bucket policy. Null leaves the
     *        store without a quarantine, which garbage collection refuses to
     *        delete from.
     * @param ObjectLayoutInterface|null $layout Null is {@see DirectoryLayout},
     *        the default. Chosen once for the lifetime of the data.
     * @param bool $orderedListing The adapter lists paths in `strcmp` order:
     *        true for S3 and S3-compatible services (UTF-8 binary key order),
     *        GCS and Azure; false for a local or FTP adapter. True streams the
     *        inventory with constant memory; false sorts it once per pass.
     *        Every consecutive pair is checked, so a wrong `true` is a
     *        {@see StoreException} on the first out-of-order key, never a
     *        silently shorter inventory.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $name,
        private FilesystemOperator $filesystem,
        private StreamFactoryInterface $streamFactory,
        private ?TemporaryUrlOptionsInterface $temporaryUrlOptions = null,
        ?string $quarantinePrefix = null,
        ?ObjectLayoutInterface $layout = null,
        private bool $orderedListing = false,
    ) {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Invalid store name \"{$name}\"");
        }

        if (
            $quarantinePrefix !== null
            && ($quarantinePrefix === '' || preg_match(self::QUARANTINE_PATTERN, $quarantinePrefix) !== 1)
        ) {
            throw new InvalidArgumentException(
                "Invalid quarantine prefix \"{$quarantinePrefix}\" for store \"{$name}\": a relative path whose first "
                . 'segment starts with "." or "_", so it can neither hold nor contain a generated key',
            );
        }

        $this->name = $name;
        $this->quarantinePrefix = $quarantinePrefix;
        $this->layout = $layout ?? new DirectoryLayout();
        /** @var Continuation<StoredObject> $objectsListing */
        $objectsListing = new Continuation();
        $this->objectsListing = $objectsListing;
        /** @var Continuation<QuarantinedObject> $quarantineListing */
        $quarantineListing = new Continuation();
        $this->quarantineListing = $quarantineListing;
    }

    /**
     * The operator this store writes through, so that
     * {@see FlysystemContentAddressableStore} cannot be given a different one.
     *
     * @internal
     */
    public function filesystem(): FilesystemOperator
    {
        return $this->filesystem;
    }

    /**
     * Whether {@see objects()} and {@see quarantined()} trust the adapter's
     * listing order (and verify it) instead of sorting.
     */
    public function orderedListing(): bool
    {
        return $this->orderedListing;
    }

    #[Override]
    public function layout(): ObjectLayoutInterface
    {
        return $this->layout;
    }

    /**
     * The reserved prefix, for diagnostics.
     *
     * @return non-empty-string|null
     */
    public function quarantinePrefix(): ?string
    {
        return $this->quarantinePrefix;
    }

    /**
     * Whether a path lies inside the quarantine — and so must not be written,
     * listed or restored to.
     */
    public function isQuarantined(string $relativePath): bool
    {
        return $this->quarantinePrefix !== null
            && ($relativePath === $this->quarantinePrefix
                || str_starts_with($relativePath, $this->quarantinePrefix . '/'));
    }

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Streams the upload to a newly generated path.
     *
     * The byte cap is enforced twice and never mid-write, because a remote
     * `PUT` has no abort: a known size over the cap is refused before any I/O,
     * and the object's actual size is verified afterwards and the object
     * removed if the body turned out larger than it claimed. The window in
     * between costs bandwidth, not correctness — and `Upload` has already
     * bounded the body once, when it spooled a non-seekable stream.
     */
    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        $object = new StoredObjectId($pathGenerator->generate($groupName, $upload, $mediaType));
        $path = $object->relativePath;

        if ($this->isQuarantined($path)) {
            throw new StoreException(
                "Generated path \"{$path}\" lies inside the quarantine of store \"{$this->name}\"",
            );
        }

        $declared = $upload->size();
        if ($maxBytes > 0 && $declared !== null && $declared > $maxBytes) {
            throw new UploadSizeException(
                "Upload of {$declared} bytes exceeds the {$maxBytes} byte limit for group \"{$groupName}\"",
            );
        }

        try {
            if ($this->filesystem->fileExists($path)) {
                // Never sharing: the caller asked for a new object, and handing
                // back somebody else's ties two logical files to bytes one of
                // them owns.
                throw new StoreException(
                    "Generated path \"{$path}\" already exists in store \"{$this->name}\"",
                );
            }

            $this->filesystem->writeStream($path, StreamWrapper::wrap($upload->stream()), $this->writeConfig($mediaType));
            $written = $this->filesystem->fileSize($path);
        } catch (FilesystemException $e) {
            throw new StoreException("Could not write \"{$path}\" to store \"{$this->name}\"", 0, $e);
        }

        if ($maxBytes > 0 && $written > $maxBytes) {
            $this->deleteObject($object);

            throw new UploadSizeException(
                "Upload of {$written} bytes exceeds the {$maxBytes} byte limit for group \"{$groupName}\"",
            );
        }

        return new StoreResult(relativePath: $path, size: max(0, $written), externalId: $path);
    }

    /**
     * Removes what the layout says belongs to the file: under
     * {@see DirectoryLayout} its key directory, derivatives included; under
     * `FlatSiblingLayout` the object and its `<base>-*` siblings, found with
     * one non-recursive listing.
     *
     * On an object store "directory" is a prefix, and `deleteDirectory()`
     * deletes everything under it — which is exactly the contract: deleting the
     * object alone would leak every derivative stored beside it, indelibly and
     * indistinguishably from live data.
     */
    #[Override]
    public function delete(File $file): void
    {
        try {
            $set = $this->layout->deletion(new StoredObjectId($file->relativePath));
        } catch (InvalidArgumentException $e) {
            // A path the layout refuses — a flat key under DirectoryLayout,
            // whose "directory" is every neighbour's too — is a store error:
            // FilestorageException handlers (-web's 404) keep working, and
            // nothing is deleted (review 1.0 A1).
            throw new StoreException(
                "Cannot delete \"{$file->relativePath}\" from store \"{$this->name}\": {$e->getMessage()}",
                0,
                $e,
            );
        }

        try {
            if (!$set->isTree()) {
                foreach ($this->members($set) as $member) {
                    $this->filesystem->delete($member);
                }
            } elseif ($set->path === $file->relativePath) {
                // An object at the store root is its own "directory": it owns
                // only itself, and deleteDirectory() on its key would remove
                // the prefix `key/` rather than the object.
                $this->filesystem->delete($set->path);
            } else {
                $this->filesystem->deleteDirectory($set->path);
            }
        } catch (FilesystemException $e) {
            throw new StoreException(
                "Could not delete \"{$set->path}\" from store \"{$this->name}\"",
                0,
                $e,
            );
        }
    }

    /**
     * A transport failure is not "does not exist" either — see
     * {@see absentOrFailed()}. `fileExists()` is already the cheapest possible
     * probe, so there is nothing left to re-check it against: its own failure
     * means the store could not be reached.
     */
    #[Override]
    public function exists(File $file): bool
    {
        try {
            return $this->filesystem->fileExists($file->relativePath);
        } catch (FilesystemException $e) {
            throw new StoreException(
                "Could not determine whether \"{$file->relativePath}\" exists in store \"{$this->name}\"",
                0,
                $e,
            );
        }
    }

    #[Override]
    public function size(File $file): ?int
    {
        try {
            return max(0, $this->filesystem->fileSize($file->relativePath));
        } catch (FilesystemException $e) {
            return $this->absentOrFailed($file->relativePath, $e);
        }
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable('@' . $this->filesystem->lastModified($file->relativePath));
        } catch (FilesystemException $e) {
            return $this->absentOrFailed($file->relativePath, $e);
        }
    }

    /**
     * Null means the object is not there. A transport failure is *not* that.
     *
     * Swallowing every `FilesystemException` made a reset connection, an
     * expired credential and a throttling response indistinguishable from a
     * missing key — and the callers read null as "gone": the download action
     * answers 404 for a live file, and `filestorage:verify` reports a whole
     * directory as missing during an outage.
     */
    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        try {
            return $this->streamFactory->createStreamFromResource(
                $this->filesystem->readStream($file->relativePath),
            );
        } catch (FilesystemException $e) {
            return $this->absentOrFailed($file->relativePath, $e);
        }
    }

    /**
     * Distinguishes "no such key" from "the store could not answer".
     *
     * Flysystem reports both as `UnableToReadFile`, so the only way to tell
     * them apart is to ask again — and `fileExists()` failing in turn is
     * itself the answer: the store is unreachable.
     *
     * @throws StoreException
     */
    private function absentOrFailed(string $path, FilesystemException $e): null
    {
        try {
            if (!$this->filesystem->fileExists($path)) {
                return null;
            }
        } catch (FilesystemException $probe) {
            throw new StoreException(
                "Store \"{$this->name}\" could not be reached while reading \"{$path}\"",
                0,
                $probe,
            );
        }

        throw new StoreException(
            "Object \"{$path}\" exists in store \"{$this->name}\" but could not be read",
            0,
            $e,
        );
    }

    #[Override]
    public function publicUrl(File $file): ?string
    {
        if (!$this->operatorHas('publicUrl')) {
            return null;
        }

        try {
            $url = $this->filesystem->publicUrl($file->relativePath);
        } catch (FilesystemException) {
            // UnableToGeneratePublicUrl implements FilesystemException, so this
            // covers both "no generator configured" and a failure inside one.
            return null;
        }

        // An operator that answers with an empty string has not produced a URL;
        // treating that as one hands the caller a link to the current page.
        return $url === '' ? null : $url;
    }

    /**
     * A presigned URL, but only one that carries the delivery policy with it.
     *
     * Without a {@see TemporaryUrlOptionsInterface}, or with one that cannot
     * express these options, the answer is null. A presigned URL that serves an
     * uploaded HTML file inline from your bucket is worse than no presigned
     * URL: the caller falls back to the application proxy, which enforces the
     * headers itself.
     */
    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt, DeliveryOptions $options): ?string
    {
        $config = $this->temporaryUrlOptions?->for($options);
        if ($config === null || !$this->operatorHas('temporaryUrl')) {
            return null;
        }

        try {
            $url = $this->filesystem->temporaryUrl($file->relativePath, $expiresAt, $config);
        } catch (FilesystemException) {
            return null;
        }

        return $url === '' ? null : $url;
    }

    /**
     * One page of the inventory, in `strcmp` order.
     *
     * A page that starts where the previous one ended continues the same
     * listing, so a whole pass costs one listing of the store, not one per
     * page (review 1.0 #6). Any other cursor starts a fresh listing and skips
     * forward. With {@see orderedListing()} the listing is streamed and its
     * order verified pair by pair; without it, it is read and sorted once per
     * pass — memory proportional to the store, once, instead of per page.
     *
     * Never lists the quarantine, nor a path with a segment starting with `.`
     * (`.keep`, `.well-known/`): no generator emits one.
     */
    #[Override]
    public function objects(
        ?string $afterPath = null,
        int $limit = 1000,
    ): iterable {
        return $this->page(
            $this->objectsListing,
            $afterPath,
            $limit,
            fn(?string $after): Iterator => $this->inventory($after),
            "Could not list store \"{$this->name}\"",
        );
    }

    private function deleteObject(StoredObjectId $object): void
    {
        try {
            // Flysystem's delete() is already idempotent on a missing object,
            // which is what a retried collection pass needs.
            $this->filesystem->delete($object->relativePath);
        } catch (FilesystemException $e) {
            throw new StoreException(
                "Could not delete \"{$object->relativePath}\" from store \"{$this->name}\"",
                0,
                $e,
            );
        }
    }

    /**
     * Moves every existing member of the set to `<prefix>/<token>/<path>`.
     *
     * The move asks for private visibility, because Flysystem's S3 copy
     * otherwise carries the source's ACL along and a deleted public object
     * would stay downloadable for the whole retention period. A member that
     * vanished between the listing and its move is skipped, as on a local
     * filesystem. Any other failure puts the members already moved back
     * before throwing, so the set stays whole (core 0.10 contract): a
     * half-quarantined set whose row stays would have the moved part purged
     * by the retention. A member that cannot go back stays under the entry,
     * where {@see quarantined()} finds it and `gc` restores it while a row
     * references it. A member put back gets the filesystem's default
     * visibility, like {@see restore()}.
     */
    #[Override]
    public function quarantine(ObjectSet $objects, DateTimeImmutable $now): ?QuarantinedObject
    {
        $quarantine = $this->requireQuarantine();

        try {
            $members = $this->members($objects);
        } catch (FilesystemException $e) {
            throw new StoreException("Could not list \"{$objects->path}\" in store \"{$this->name}\"", 0, $e);
        }

        $token = QuarantinedObject::newToken($now);
        $moved = [];

        foreach ($members as $member) {
            try {
                $this->filesystem->move(
                    $member,
                    $quarantine . '/' . $token . '/' . $member,
                    [Config::OPTION_VISIBILITY => Visibility::PRIVATE],
                );
            } catch (FilesystemException $e) {
                if ($this->vanished($member)) {
                    continue;
                }

                throw new StoreException(
                    "Could not quarantine \"{$member}\" in store \"{$this->name}\""
                    . $this->rollBack($quarantine . '/' . $token, $moved),
                    0,
                    $e,
                );
            }

            $moved[] = new StoredObjectId($member);
        }

        return $moved === [] ? null : new QuarantinedObject($token, $moved);
    }

    /**
     * Puts the entry's objects back unless their key is taken again.
     *
     * Put-if-absent is a `fileExists()` followed by a `move()`: atomic on no
     * remote adapter, and on S3 the move itself is a copy and a delete. That is
     * sufficient for what garbage collection restores — content-addressed keys,
     * where a writer that re-published meanwhile wrote the same bytes, so
     * losing the race to it replaces the object with an identical one. A
     * restored object gets this filesystem's default visibility, like a fresh
     * write, not the private one it had in the quarantine.
     */
    #[Override]
    public function restore(QuarantinedObject $entry): void
    {
        $base = $this->requireQuarantine() . '/' . $entry->token;

        foreach ($entry->objects as $object) {
            $target = $object->relativePath;
            if ($this->isQuarantined($target)) {
                throw new StoreException(
                    "Cannot restore \"{$target}\" into the quarantine of store \"{$this->name}\"",
                );
            }

            $held = $base . '/' . $target;

            try {
                if (!$this->filesystem->fileExists($held) || $this->filesystem->fileExists($target)) {
                    continue;
                }

                $this->filesystem->move($held, $target, [Config::OPTION_RETAIN_VISIBILITY => false]);
            } catch (FilesystemException $e) {
                throw new StoreException(
                    "Could not restore \"{$target}\" in store \"{$this->name}\"",
                    0,
                    $e,
                );
            }
        }

        $this->purge($entry);
    }

    #[Override]
    public function purge(QuarantinedObject $entry): void
    {
        try {
            $this->filesystem->deleteDirectory($this->requireQuarantine() . '/' . $entry->token);
        } catch (FilesystemException $e) {
            throw new StoreException(
                "Could not purge quarantine entry \"{$entry->token}\" of store \"{$this->name}\"",
                0,
                $e,
            );
        }
    }

    /**
     * One shallow listing of the prefix per pass, continued across pages the
     * way {@see objects()} is, then one deep listing per entry yielded. An
     * orphan sweep leaves one entry per object, so the tokens are not "a
     * handful" (review 1.0 #8): re-listing them all for every page was
     * quadratic in the size of the sweep.
     */
    #[Override]
    public function quarantined(
        ?string $afterToken = null,
        int $limit = 1000,
    ): iterable {
        $quarantine = $this->requireQuarantine();

        return $this->page(
            $this->quarantineListing,
            $afterToken,
            $limit,
            fn(?string $after): Iterator => $this->quarantineEntries($quarantine, $after),
            "Could not list the quarantine of store \"{$this->name}\"",
        );
    }

    /**
     * Only a missing prefix is reported. Whether the prefix is publicly
     * readable is a bucket policy this store cannot inspect, and a move on a
     * remote adapter is never atomic — which the restore path is written for.
     */
    #[Override]
    public function quarantineProblem(): ?string
    {
        if ($this->quarantinePrefix !== null) {
            return null;
        }

        return "Store \"{$this->name}\" has no quarantine prefix, so filestorage:gc cannot delete from it. "
            . 'Pass quarantinePrefix: a private prefix such as ".quarantine", excluded from any public read policy';
    }

    /**
     * Serves one page from a listing, continuing the parked one when the
     * cursor is where it stopped.
     *
     * The listing is driven by hand — `valid()`, `current()`, `next()` —
     * because a generator cannot be rewound, and a `foreach` would try.
     *
     * @template T of StoredObject|QuarantinedObject
     *
     * @param Continuation<T> $continuation
     * @param \Closure(?string): Iterator<int, T> $start
     *
     * @return Generator<int, T>
     *
     * @throws StoreException
     */
    private function page(
        Continuation $continuation,
        ?string $after,
        int $limit,
        \Closure $start,
        string $failure,
    ): Generator {
        try {
            $entries = $continuation->resume($after) ?? $start($after);
            $yielded = 0;

            while ($entries->valid()) {
                $entry = $entries->current();
                \assert($entry !== null);
                $key = $entry instanceof StoredObject ? $entry->id->relativePath : $entry->token;
                $continuation->park($entries, $key);

                yield $entry;

                if (++$yielded >= $limit) {
                    return;
                }

                if ($continuation->holds($entries)) {
                    $entries->next();
                } else {
                    // Another page of this store took or replaced the parked
                    // listing while this one was suspended. Sharing one
                    // iterator would make each skip the other's entries.
                    $entries = $start($key);
                }
            }
            // An exhausted listing stays parked: the caller's next page, from
            // this page's last key, answers "nothing more" without listing
            // the store again, and is then forgotten.
        } catch (FilesystemException $e) {
            $continuation->clear();

            throw new StoreException($failure, 0, $e);
        } catch (StoreException $e) {
            $continuation->clear();

            throw $e;
        }
    }

    /**
     * Every object after the cursor, in `strcmp` order.
     *
     * @return Generator<int, StoredObject, mixed, void>
     *
     * @throws FilesystemException
     * @throws StoreException
     */
    private function inventory(?string $after): Generator
    {
        $listing = $this->filesystem->listContents('', true);

        if ($this->orderedListing) {
            $previous = null;
            foreach ($listing as $attributes) {
                if (!$attributes instanceof FileAttributes) {
                    continue;
                }

                $path = $attributes->path();
                $this->assertAscending($previous, $path);
                $previous = $path;

                if ($this->listable($path) && ($after === null || strcmp($path, $after) > 0)) {
                    yield $this->storedObject($path, $attributes->lastModified(), $attributes->fileSize());
                }
            }

            return;
        }

        // Not sortByPath(): that keeps whole FileAttributes objects. A path,
        // a timestamp and a size are all a page needs.
        /** @var list<array{string, int|null, int|null}> $entries */
        $entries = [];
        foreach ($listing as $attributes) {
            if (!$attributes instanceof FileAttributes) {
                continue;
            }

            $path = $attributes->path();
            if ($this->listable($path) && ($after === null || strcmp($path, $after) > 0)) {
                $entries[] = [$path, $attributes->lastModified(), $attributes->fileSize()];
            }
        }
        unset($listing);

        usort($entries, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        foreach ($entries as [$path, $modified, $size]) {
            yield $this->storedObject($path, $modified, $size);
        }
    }

    /**
     * Every quarantine entry after the cursor, in token order, skipping
     * entries already emptied.
     *
     * @return Generator<int, QuarantinedObject, mixed, void>
     *
     * @throws FilesystemException
     * @throws StoreException
     */
    private function quarantineEntries(string $quarantine, ?string $after): Generator
    {
        $tokens = [];
        $previous = null;
        foreach ($this->filesystem->listContents($quarantine, false) as $attributes) {
            if (!$attributes->isDir()) {
                continue;
            }

            // DirectoryAttributes trims slashes itself, so an adapter that
            // reports `prefix/token/` still yields the bare token.
            $token = substr($attributes->path(), \strlen($quarantine) + 1);
            if ($this->orderedListing) {
                $this->assertAscending($previous, $token);
                $previous = $token;
            }

            if (QuarantinedObject::isToken($token) && ($after === null || strcmp($token, $after) > 0)) {
                $tokens[] = $token;
            }
        }

        if (!$this->orderedListing) {
            sort($tokens, SORT_STRING);
        }

        foreach ($tokens as $token) {
            $base = $quarantine . '/' . $token . '/';
            $objects = [];
            foreach ($this->filesystem->listContents($base, true) as $attributes) {
                if ($attributes instanceof FileAttributes) {
                    $objects[] = new StoredObjectId(substr($attributes->path(), \strlen($base)));
                }
            }

            if ($objects !== []) {
                yield new QuarantinedObject($token, $objects);
            }
        }
    }

    /**
     * @throws StoreException
     */
    private function assertAscending(?string $previous, string $current): void
    {
        if ($previous !== null && strcmp($current, $previous) <= 0) {
            throw new StoreException(
                "Store \"{$this->name}\" was declared with orderedListing, but its adapter does not list in strcmp "
                . "order: \"{$current}\" after \"{$previous}\". Paging on would skip objects; construct the store "
                . 'without orderedListing',
            );
        }
    }

    /**
     * Neither the quarantine nor a hidden path (a segment starting with `.`).
     */
    private function listable(string $path): bool
    {
        return !$this->isQuarantined($path)
            && !str_starts_with($path, '.')
            && !str_contains($path, '/.');
    }

    /**
     * Both come with the listing (S3's ListObjectsV2 carries LastModified and
     * Size), so this costs no request per object. An adapter that leaves the
     * timestamp out gets null, and the orphan sweep never deletes an undated
     * object.
     */
    private function storedObject(string $path, ?int $modified, ?int $size): StoredObject
    {
        return new StoredObject(
            id: new StoredObjectId($path),
            lastModified: $modified === null ? null : new DateTimeImmutable('@' . $modified),
            size: $size,
        );
    }

    /**
     * Puts already moved members back after a failed quarantine.
     *
     * @param list<StoredObjectId> $moved
     *
     * @return string Appended to the failure message: what could not go back.
     */
    private function rollBack(string $entry, array $moved): string
    {
        $stuck = [];
        foreach ($moved as $object) {
            $target = $object->relativePath;

            try {
                if ($this->filesystem->fileExists($target)) {
                    $stuck[] = $target;

                    continue;
                }

                $this->filesystem->move(
                    $entry . '/' . $target,
                    $target,
                    [Config::OPTION_RETAIN_VISIBILITY => false],
                );
            } catch (FilesystemException) {
                $stuck[] = $target;
            }
        }

        if ($stuck !== []) {
            return '; left in the quarantine, restored by gc while a row references them: ' . implode(', ', $stuck);
        }

        try {
            $this->filesystem->deleteDirectory($entry);
        } catch (FilesystemException) {
            // Empty after the moves back; quarantined() skips an empty entry.
        }

        return '; nothing was moved';
    }

    /**
     * `ContentType` is the S3 adapter's key (others ignore it): the stored
     * object's type then comes from the detected media type, not from
     * Flysystem guessing again by extension or content — it is what a public
     * URL serves (review 1.0 #15).
     *
     * Except for a scriptable type ({@see ActiveMediaTypes::scriptable()}):
     * core's `ExtensionMap` gives those the inert `.bin` key precisely so a
     * public bucket serves them as `application/octet-stream`, and storing
     * `image/svg+xml` would put the stored XSS back by path. Those are left to
     * the adapter, which types them by the key's extension — `.bin`, or the
     * extension an application deliberately mapped back.
     *
     * @return array<string, string>
     */
    private function writeConfig(?string $mediaType): array
    {
        $mediaType = $mediaType === null ? '' : str_replace(["\r", "\n", "\0"], '', $mediaType);

        if ($mediaType === '' || ActiveMediaTypes::scriptable()->contains($mediaType)) {
            return [];
        }

        return ['ContentType' => $mediaType];
    }

    /**
     * The files a set describes, by relative path.
     *
     * @return list<non-empty-string>
     *
     * @throws FilesystemException
     */
    private function members(ObjectSet $set): array
    {
        $members = [];

        if ($set->isTree()) {
            if ($this->filesystem->fileExists($set->path)) {
                return [$set->path];
            }

            foreach ($this->filesystem->listContents($set->path, true) as $attributes) {
                if ($attributes instanceof FileAttributes && !$this->isQuarantined($attributes->path())) {
                    $members[] = (new StoredObjectId($attributes->path()))->relativePath;
                }
            }
        } else {
            foreach ($this->filesystem->listContents($set->directory() ?? '', false) as $attributes) {
                if ($attributes instanceof FileAttributes && $set->contains($attributes->path())) {
                    $members[] = (new StoredObjectId($attributes->path()))->relativePath;
                }
            }
        }

        return $members;
    }

    /**
     * Whether a member whose move failed is simply gone. A probe that fails
     * in turn means the store could not be reached, which is not "gone".
     */
    private function vanished(string $path): bool
    {
        try {
            return !$this->filesystem->fileExists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * @return non-empty-string
     *
     * @throws StoreException
     */
    private function requireQuarantine(): string
    {
        if ($this->quarantinePrefix === null) {
            throw new StoreException(
                "Store \"{$this->name}\" has no quarantine prefix configured, so it cannot quarantine objects",
            );
        }

        return $this->quarantinePrefix;
    }

    /**
     * In Flysystem 3 the URL methods are `@method` annotations on
     * `FilesystemReader` — "Will be added in 4.0" — not interface declarations.
     * `Filesystem` and `MountManager` have them; a hand-rolled operator or a
     * decorator written against the interface may not, and calling one there is
     * a fatal `Call to undefined method` rather than a catchable exception.
     */
    private function operatorHas(string $method): bool
    {
        return method_exists($this->filesystem, $method);
    }
}
