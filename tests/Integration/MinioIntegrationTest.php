<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Integration;

use Aws\S3\S3Client;
use DateTimeImmutable;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemContentAddressableStore;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\FixedPathGenerator;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\RacingFilesystem;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The S3 paths, against a real S3 implementation.
 *
 * The unit suite runs on the in-memory adapter, which has no URL generator, no
 * multipart upload and no eventual consistency — so it proves the store's own
 * logic and nothing about S3. Presigned URLs in particular cannot be tested any
 * other way: their whole content is a signature a server has to accept.
 *
 * Skipped when no endpoint is configured, so `composer test:integration` is
 * safe to run anywhere. CI supplies an S3 gateway (SeaweedFS — MinIO deleted
 * its images and gated quay.io in September 2026) and fails the job if it
 * never becomes reachable, because a suite that skips itself looks exactly
 * like a suite that passed.
 *
 * ```bash
 * docker run -d --name weed -p 8333:8333 chrislusf/seaweedfs server -dir=/data -s3
 * FILESTORAGE_S3_ENDPOINT=http://127.0.0.1:8333 \
 * FILESTORAGE_S3_KEY=minioadmin FILESTORAGE_S3_SECRET=minioadmin \
 * FILESTORAGE_S3_BUCKET=filestorage-test make test-integration
 * ```
 */
#[Test]
#[CoversNothing]
final class MinioIntegrationTest
{
    private const string KEY = 'sha/e3/b0/e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855/original';

    private const string QUARANTINE = '.quarantine-it';

    private ?RacingFilesystem $filesystem = null;
    private ?FlysystemStore $store = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $endpoint = getenv('FILESTORAGE_S3_ENDPOINT');
        if ($endpoint === false || $endpoint === '') {
            return;
        }

        $bucket = (string) (getenv('FILESTORAGE_S3_BUCKET') ?: 'filestorage-test');
        $client = new S3Client([
            'version' => 'latest',
            'region' => (string) (getenv('FILESTORAGE_S3_REGION') ?: 'us-east-1'),
            'endpoint' => $endpoint,
            // MinIO serves buckets as path segments, not as subdomains
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => (string) (getenv('FILESTORAGE_S3_KEY') ?: 'minioadmin'),
                'secret' => (string) (getenv('FILESTORAGE_S3_SECRET') ?: 'minioadmin'),
            ],
        ]);

        if (!$client->doesBucketExist($bucket)) {
            $client->createBucket(['Bucket' => $bucket]);
        }

        $this->filesystem = new RacingFilesystem(new AwsS3V3Adapter($client, $bucket));
        $this->store = new FlysystemStore(
            name: 'minio',
            filesystem: $this->filesystem,
            streamFactory: Fixtures::factory(),
            temporaryUrlOptions: new S3TemporaryUrlOptions(),
            quarantinePrefix: self::QUARANTINE,
        );
    }

    public function writesReadsAndDeletesAgainstARealBucket(): void
    {
        $store = $this->requireStore();

        $result = $store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());
        $file = Fixtures::file(relativePath: $result->relativePath, storeName: 'minio');

        Assert::same($result->size, 5);
        Assert::true($store->exists($file));
        Assert::same((string) $store->stream($file)?->getContents(), 'hello');

        $store->delete($file);
        Assert::false($store->exists($file));
    }

    /**
     * A body big enough that the SDK switches to multipart. The stream wrapper
     * has to survive being read in parts, seeked and stat'ed by the uploader —
     * which the in-memory adapter never does.
     */
    public function amultipartSizedBodyUploadsIntact(): void
    {
        $store = $this->requireStore();

        $contents = str_repeat('abcdefgh', 1_500_000); // 12 MB, over the 5 MB part size
        $result = $store->write(Fixtures::upload($contents), 'common', new RandomPathGenerator());
        $file = Fixtures::file(relativePath: $result->relativePath, storeName: 'minio');

        Assert::same($result->size, strlen($contents));
        Assert::same($store->size($file), strlen($contents));
        Assert::same(md5((string) $store->stream($file)?->getContents()), md5($contents));

        $store->delete($file);
    }

    /**
     * The claim that makes presigned URLs usable at all: the delivery policy
     * travels inside the signature, and S3 honours it on the response. Fetched
     * for real, because a URL that is merely well-formed proves nothing.
     */
    public function aPresignedUrlCarriesTheDeliveryPolicy(): void
    {
        $store = $this->requireStore();

        $result = $store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());
        $file = Fixtures::file(relativePath: $result->relativePath, storeName: 'minio');

        $url = $store->temporaryUrl($file, new DateTimeImmutable('+10 minutes'), Fixtures::deliveryOptions());
        Assert::true($url !== null);

        $context = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = file_get_contents($url, use_include_path: false, context: $context);
        $headers = implode("\n", $http_response_header ?? []);

        Assert::same($body, 'hello');
        Assert::string($headers)->contains('200');
        Assert::string(strtolower($headers))->contains('content-disposition: attachment');

        $store->delete($file);
    }

    /**
     * A presigned URL is only useful for media if the server honours `Range`:
     * that is how a player seeks and how a download resumes. This store has no
     * range primitive of its own, so the bucket is the one serving it.
     */
    public function aPresignedUrlServesARangeRequest(): void
    {
        $store = $this->requireStore();

        $result = $store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());
        $file = Fixtures::file(relativePath: $result->relativePath, storeName: 'minio');

        $url = $store->temporaryUrl($file, new DateTimeImmutable('+10 minutes'), Fixtures::deliveryOptions());
        Assert::true($url !== null);

        $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Range: bytes=1-3\r\n"]]);
        $body = file_get_contents($url, use_include_path: false, context: $context);
        $headers = $http_response_header ?? [];

        Assert::same($body, 'ell');
        Assert::string($headers[0] ?? '')->contains('206');
        Assert::string(strtolower(implode("\n", $headers)))->contains('content-range: bytes 1-3/5');

        $store->delete($file);
    }

    /**
     * Two writers, identical content, one object — and the second is told it
     * reused rather than created.
     */
    public function contentAddressedWritesConvergeOnOneObject(): void
    {
        $flysystemStore = $this->requireStore();
        if (!$this->filesystem instanceof RacingFilesystem) {
            throw new SkipTest('MinIO endpoint not configured (FILESTORAGE_S3_ENDPOINT)');
        }

        $store = new FlysystemContentAddressableStore(
            store: $flysystemStore,
            semantics: AdapterSemantics::guaranteed(),
        );
        $object = new StoredObjectId(self::KEY);

        $first = $store->putIfAbsent(Fixtures::upload('hello'), $object);
        $second = $store->putIfAbsent(Fixtures::upload('hello'), $object);

        Assert::true($first->created);
        Assert::false($second->created);
        Assert::same($second->size, 5);

        $this->filesystem?->delete($object->relativePath);
    }

    /**
     * Two real PUTs to one key: the second writer publishes while the first is
     * between its existence check and its write. Both report `created` — the
     * flag is advisory — and the object is the one whole body both uploaded.
     */
    public function concurrentContentAddressedWritesLeaveOneWholeObject(): void
    {
        $flysystemStore = $this->requireStore();
        $filesystem = $this->filesystem;
        if (!$filesystem instanceof RacingFilesystem) {
            throw new SkipTest('MinIO endpoint not configured (FILESTORAGE_S3_ENDPOINT)');
        }

        $store = new FlysystemContentAddressableStore(
            store: $flysystemStore,
            semantics: AdapterSemantics::guaranteed(),
        );
        $object = new StoredObjectId(self::KEY);
        $this->filesystem?->delete($object->relativePath);

        $second = null;
        $filesystem->raceOnNextExistenceCheck(static function () use ($store, $object, &$second): void {
            $second = $store->putIfAbsent(Fixtures::upload('hello'), $object);
        });

        $first = $store->putIfAbsent(Fixtures::upload('hello'), $object);

        Assert::instanceOf($second, StoreResult::class);
        Assert::true($first->created);
        Assert::true($second->created);
        Assert::same($first->size, 5);
        Assert::same($second->size, 5);
        Assert::same($filesystem->read(self::KEY), 'hello');

        $this->filesystem?->delete($object->relativePath);
    }

    /**
     * The timestamp comes from ListObjectsV2 itself — the orphan sweep's
     * whole safety against uploads in flight rests on it being there.
     */
    public function theInventoryWalksTheBucketWithTimestamps(): void
    {
        $store = $this->requireStore();
        $before = time() - 60;

        $result = $store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());

        $found = null;
        foreach ($store->objects() as $object) {
            Assert::false(str_starts_with($object->id->relativePath, self::QUARANTINE . '/'));
            if ($object->id->relativePath === $result->relativePath) {
                $found = $object;
            }
        }

        Assert::instanceOf($found, StoredObject::class);
        Assert::true(($found->lastModified?->getTimestamp() ?? 0) >= $before);
        Assert::true(($found->lastModified?->getTimestamp() ?? PHP_INT_MAX) <= time() + 60);
        Assert::same($found->size, 5);

        $store->delete(Fixtures::file(relativePath: $result->relativePath, storeName: 'minio'));
    }

    /**
     * On S3 a move is a copy and a delete, and "directories" are prefixes:
     * the round trip has to hold over the real API, not just in memory.
     */
    public function aKeyDirectoryRoundTripsThroughTheQuarantine(): void
    {
        $store = $this->requireStore();
        $filesystem = $this->requireFilesystem();
        $result = $store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());
        $id = new StoredObjectId($result->relativePath);
        $sibling = $id->directory() . '/thumb-v1.webp';
        $filesystem->write($sibling, 'thumb');

        $entry = $store->quarantine($store->layout()->deletion($id), new DateTimeImmutable());

        Assert::instanceOf($entry, QuarantinedObject::class);
        Assert::same(\count($entry->objects), 2);
        Assert::false($filesystem->fileExists($result->relativePath));
        Assert::false($filesystem->fileExists($sibling));
        Assert::true($this->isListed($store, $entry->token));
        Assert::false($this->inInventory($store, $result->relativePath));

        $store->restore($entry);

        Assert::same($filesystem->read($result->relativePath), 'hello');
        Assert::same($filesystem->read($sibling), 'thumb');
        Assert::false($this->isListed($store, $entry->token));

        $again = $store->quarantine($store->layout()->deletion($id), new DateTimeImmutable());
        \assert($again instanceof QuarantinedObject);
        $store->purge($again);

        Assert::false($filesystem->fileExists($result->relativePath));
        Assert::false($this->isListed($store, $again->token));
    }

    /**
     * Restore is put-if-absent: a key re-published while it was quarantined
     * keeps the writer's object.
     */
    public function aRestoreKeepsARePublication(): void
    {
        $store = $this->requireStore();
        $filesystem = $this->requireFilesystem();
        $filesystem->write(self::KEY, 'quarantined');

        $entry = $store->quarantine(
            $store->layout()->deletion(new StoredObjectId(self::KEY)),
            new DateTimeImmutable(),
        );
        \assert($entry instanceof QuarantinedObject);
        $filesystem->write(self::KEY, 'republished');

        $store->restore($entry);

        Assert::same($filesystem->read(self::KEY), 'republished');
        Assert::false($this->isListed($store, $entry->token));

        $filesystem->delete(self::KEY);
    }

    /**
     * The flat layout resolves siblings with one delimiter listing of the
     * directory; S3 answers that with CommonPrefixes, which must not count.
     */
    public function theFlatLayoutDeletesSiblingsInABucket(): void
    {
        $filesystem = $this->requireFilesystem();
        $directory = 'flat/' . bin2hex(random_bytes(4));
        foreach (['abc.jpg', 'abc-s-v1.webp', 'abcd.jpg', 'abc/nested.jpg'] as $name) {
            $filesystem->write($directory . '/' . $name, $name);
        }
        $store = new FlysystemStore(
            name: 'minio',
            filesystem: $filesystem,
            streamFactory: Fixtures::factory(),
            layout: new FlatSiblingLayout(),
        );

        $store->delete(Fixtures::file(relativePath: $directory . '/abc.jpg', storeName: 'minio'));

        Assert::false($filesystem->fileExists($directory . '/abc.jpg'));
        Assert::false($filesystem->fileExists($directory . '/abc-s-v1.webp'));
        Assert::true($filesystem->fileExists($directory . '/abcd.jpg'));
        Assert::true($filesystem->fileExists($directory . '/abc/nested.jpg'));

        $filesystem->deleteDirectory($directory);
    }

    /**
     * An uploaded SVG in a group whose policy says inline: S3 must answer
     * `attachment`, because a presigned response cannot carry `nosniff` and
     * an inline SVG from the bucket's origin runs as that origin (review 1.0
     * A5). Fetched for real.
     */
    public function aPresignedUrlServesActiveContentAsAnAttachment(): void
    {
        $store = $this->requireStore();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $result = $store->write(Fixtures::upload($svg, 'x.svg'), 'common', new RandomPathGenerator(), 'image/svg+xml');
        $file = File::create(
            id: 'svg-1',
            storeName: 'minio',
            groupName: 'common',
            relativePath: $result->relativePath,
            originalName: 'x.svg',
            size: \strlen($svg),
            createdAt: new DateTimeImmutable(),
            mimeType: 'image/svg+xml',
        );
        $options = DeliveryOptions::fromFile($file, new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: false));

        $url = $store->temporaryUrl($file, new DateTimeImmutable('+10 minutes'), $options);
        Assert::true($url !== null);

        $body = file_get_contents($url, use_include_path: false, context: stream_context_create(['http' => ['ignore_errors' => true]]));
        $headers = strtolower(implode("\n", $http_response_header ?? []));

        Assert::same($body, $svg);
        Assert::string($headers)->contains('content-disposition: attachment');

        $store->delete($file);
    }

    /**
     * The detected type is the object's own Content-Type — what a public URL
     * serves — not Flysystem's second guess from the extension or the bytes
     * (a `.bin` key holding `hello` would be text/plain).
     */
    public function theDetectedMediaTypeIsTheObjectsContentType(): void
    {
        $store = $this->requireStore();
        $filesystem = $this->requireFilesystem();
        $path = 'ct/' . bin2hex(random_bytes(8)) . '/original.bin';

        $result = $store->write(Fixtures::upload('hello'), 'common', new FixedPathGenerator($path), 'image/png');

        Assert::same($filesystem->mimeType($result->relativePath), 'image/png');

        $store->delete(Fixtures::file(relativePath: $result->relativePath, storeName: 'minio'));
    }

    /**
     * Core keys an SVG `.bin`, so a public bucket serves it as a download;
     * storing the detected `image/svg+xml` as Content-Type would serve it
     * inline by path again (core review 1.0 B5).
     */
    public function aScriptableTypeIsNotStoredAsItsOwnContentType(): void
    {
        $store = $this->requireStore();
        $filesystem = $this->requireFilesystem();

        $result = $store->write(Fixtures::upload('<svg/>'), 'common', new RandomPathGenerator(), 'image/svg+xml');

        Assert::string($result->relativePath)->endsWith('.bin');
        Assert::same($filesystem->mimeType($result->relativePath), 'application/octet-stream');

        $store->delete(Fixtures::file(relativePath: $result->relativePath, storeName: 'minio'));
    }

    /**
     * More keys than one ListObjectsV2 page (1000), paged the way gc does
     * with orderedListing declared: the gateway's key order is verified pair
     * by pair across its own page boundary, and nothing is lost or repeated
     * (review 1.0 #6, #7).
     */
    public function aDeclaredOrderedPassCrossesListingPagesWhole(): void
    {
        $filesystem = $this->requireFilesystem();
        $prefix = 'pass-' . bin2hex(random_bytes(4));
        $paths = [];
        for ($i = 0; $i < 1100; $i++) {
            $path = \sprintf('%s/%s/original.txt', $prefix, md5((string) $i));
            $filesystem->write($path, 'x');
            $paths[] = $path;
        }
        sort($paths, SORT_STRING);
        $store = new FlysystemStore(
            name: 'minio',
            filesystem: $filesystem,
            streamFactory: Fixtures::factory(),
            quarantinePrefix: self::QUARANTINE,
            orderedListing: true,
        );

        $seen = [];
        $after = null;
        while (($page = iterator_to_array($store->objects($after, 300), preserve_keys: false)) !== []) {
            foreach ($page as $object) {
                $after = $object->id->relativePath;
                if (str_starts_with($after, $prefix . '/')) {
                    $seen[] = $after;
                }
            }
        }

        Assert::same($seen, $paths);

        $filesystem->deleteDirectory($prefix);
    }

    /**
     * A deleted public object must not stay downloadable for the retention:
     * the move into the quarantine asks for private visibility. Checked
     * through GetObjectAcl where the gateway implements it.
     */
    public function aQuarantinedObjectIsPrivate(): void
    {
        $store = $this->requireStore();
        $filesystem = $this->requireFilesystem();
        $path = 'acl/' . bin2hex(random_bytes(8)) . '/original.txt';
        $filesystem->write($path, 'public', ['visibility' => 'public']);
        if ($filesystem->visibility($path) !== 'public') {
            throw new SkipTest('The gateway does not report object ACLs; nothing to compare against');
        }

        $entry = $store->quarantine($store->layout()->deletion(new StoredObjectId($path)), new DateTimeImmutable());
        \assert($entry instanceof QuarantinedObject);

        Assert::same($filesystem->visibility(self::QUARANTINE . '/' . $entry->token . '/' . $path), 'private');

        $store->purge($entry);
    }

    private function isListed(FlysystemStore $store, string $token): bool
    {
        foreach ($store->quarantined() as $entry) {
            if ($entry->token === $token) {
                return true;
            }
        }

        return false;
    }

    private function inInventory(FlysystemStore $store, string $path): bool
    {
        foreach ($store->objects() as $object) {
            if ($object->id->relativePath === $path) {
                return true;
            }
        }

        return false;
    }

    private function requireFilesystem(): RacingFilesystem
    {
        $this->requireStore();
        \assert($this->filesystem instanceof RacingFilesystem);

        return $this->filesystem;
    }

    private function requireStore(): FlysystemStore
    {
        if (!$this->store instanceof FlysystemStore) {
            throw new SkipTest('MinIO endpoint not configured (FILESTORAGE_S3_ENDPOINT)');
        }

        return $this->store;
    }
}
