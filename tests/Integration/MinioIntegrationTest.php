<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Integration;

use Aws\S3\S3Client;
use DateTimeImmutable;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemContentAddressableStore;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
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
 * safe to run anywhere. CI supplies MinIO and fails the job if it never becomes
 * reachable, because a suite that skips itself looks exactly like a suite that
 * passed.
 *
 * ```bash
 * docker run -d --name minio -p 9000:9000 quay.io/minio/minio server /data
 * FILESTORAGE_S3_ENDPOINT=http://127.0.0.1:9000 \
 * FILESTORAGE_S3_KEY=minioadmin FILESTORAGE_S3_SECRET=minioadmin \
 * FILESTORAGE_S3_BUCKET=filestorage-test make test-integration
 * ```
 */
#[Test]
#[CoversNothing]
final class MinioIntegrationTest
{
    private const string KEY = 'sha/e3/b0/e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855/original';

    private ?Filesystem $filesystem = null;
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

        $this->filesystem = new Filesystem(new AwsS3V3Adapter($client, $bucket));
        $this->store = new FlysystemStore(
            name: 'minio',
            filesystem: $this->filesystem,
            streamFactory: Fixtures::factory(),
            temporaryUrlOptions: new S3TemporaryUrlOptions(),
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
        $body = file_get_contents($url, false, $context);
        $headers = implode("\n", $http_response_header ?? []);

        Assert::same($body, 'hello');
        Assert::string($headers)->contains('200');
        Assert::string(strtolower($headers))->contains('content-disposition: attachment');

        $store->delete($file);
    }

    /**
     * Two writers, identical content, one object — and the second is told it
     * reused rather than created, which is what lets the ledger record a
     * reference instead of a second blob.
     */
    public function contentAddressedWritesConvergeOnOneObject(): void
    {
        $flysystemStore = $this->requireStore();
        if (!$this->filesystem instanceof Filesystem) {
            throw new SkipTest('MinIO endpoint not configured (FILESTORAGE_S3_ENDPOINT)');
        }

        $store = new FlysystemContentAddressableStore(
            store: $flysystemStore,
            filesystem: $this->filesystem,
            semantics: AdapterSemantics::guaranteed(),
        );
        $object = new StoredObjectId(self::KEY);

        $first = $store->putIfAbsent(Fixtures::upload('hello'), $object);
        $second = $store->putIfAbsent(Fixtures::upload('hello'), $object);

        Assert::true($first->created);
        Assert::false($second->created);
        Assert::same($second->size, 5);

        $store->deleteObject($object);
    }

    public function theInventoryWalksTheBucket(): void
    {
        $store = $this->requireStore();

        $result = $store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());

        $paths = array_map(
            static fn(StoredObjectId $o): string => $o->relativePath,
            iterator_to_array($store->objects(), false),
        );

        Assert::true(\in_array($result->relativePath, $paths, true));

        $store->delete(Fixtures::file(relativePath: $result->relativePath, storeName: 'minio'));
    }

    private function requireStore(): FlysystemStore
    {
        if (!$this->store instanceof FlysystemStore) {
            throw new SkipTest('MinIO endpoint not configured (FILESTORAGE_S3_ENDPOINT)');
        }

        return $this->store;
    }
}
