<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem;

use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
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
 * @api
 */
final readonly class FlysystemStore implements StoreUrlProviderInterface, MaintenanceStoreInterface
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    /** @var non-empty-string */
    private string $name;

    /**
     * @param TemporaryUrlOptionsInterface|null $temporaryUrlOptions Absent means
     *        no presigned URLs: without a mapping this store cannot promise the
     *        response headers a delivery policy asks for.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $name,
        private FilesystemOperator $filesystem,
        private StreamFactoryInterface $streamFactory,
        private ?TemporaryUrlOptionsInterface $temporaryUrlOptions = null,
        private ?AdapterSemantics $semantics = null,
    ) {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Invalid store name \"{$name}\"");
        }

        $this->name = $name;
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

        $declared = $upload->size();
        if ($maxBytes > 0 && $declared !== null && $declared > $maxBytes) {
            throw new UploadTooLargeException(
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

            $this->filesystem->writeStream($path, StreamWrapper::wrap($upload->stream()));
            $written = $this->filesystem->fileSize($path);
        } catch (FilesystemException $e) {
            throw new StoreException("Could not write \"{$path}\" to store \"{$this->name}\"", 0, $e);
        }

        if ($maxBytes > 0 && $written > $maxBytes) {
            $this->deleteObject($object);

            throw new UploadTooLargeException(
                "Upload of {$written} bytes exceeds the {$maxBytes} byte limit for group \"{$groupName}\"",
            );
        }

        return new StoreResult(relativePath: $path, size: max(0, $written), externalId: $path);
    }

    /**
     * Removes the file's directory, derivatives included.
     *
     * On an object store "directory" is a prefix, and `deleteDirectory()`
     * deletes everything under it — which is exactly the contract: deleting the
     * object alone would leak every preview generated beside it, indelibly and
     * indistinguishably from live data.
     */
    #[Override]
    public function delete(File $file): void
    {
        try {
            $this->filesystem->deleteDirectory($file->directory());
        } catch (FilesystemException $e) {
            throw new StoreException(
                "Could not delete \"{$file->directory()}\" from store \"{$this->name}\"",
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

    #[Override]
    public function objects(?string $afterPath = null, int $limit = 1000): iterable
    {
        try {
            $yielded = 0;

            // Not sortByPath(): that is toArray() + usort() under the hood, so
            // it materialises and orders the entire bucket before the first
            // yield — and `filestorage:gc` calls this once per page, which
            // turns a resumable cursor into a full listing per page and an
            // out-of-memory failure on a large store.
            //
            // The cursor needs the listing to be in strcmp order, and whether
            // it is belongs to the adapter: S3 returns keys in UTF-8 binary
            // order, a local filesystem returns directory order. The package
            // cannot verify that, so the application declares it the same way
            // it declares atomicity and immutability — and when it has not,
            // this falls back to sorting and pays the price knowingly.
            $listing = $this->filesystem->listContents('', true);
            $entries = $this->semantics?->orderedListing === true ? $listing : $listing->sortByPath();

            /** @var StorageAttributes $attributes */
            foreach ($entries as $attributes) {
                if (!$attributes->isFile()) {
                    continue;
                }

                $path = $attributes->path();
                if ($afterPath !== null && strcmp($path, $afterPath) <= 0) {
                    continue;
                }

                yield new StoredObjectId($path);

                if (++$yielded >= $limit) {
                    return;
                }
            }
        } catch (FilesystemException $e) {
            throw new StoreException("Could not list store \"{$this->name}\"", 0, $e);
        }
    }

    #[Override]
    public function deleteObject(StoredObjectId $object): void
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
