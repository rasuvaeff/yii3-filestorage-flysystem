<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\FixedPathGenerator;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\StubUrlGenerator;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\UnreachableFilesystem;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\UnsizedStream;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FlysystemStore::class)]
final class FlysystemStoreTest
{
    private Filesystem $filesystem;
    private FlysystemStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->filesystem = Fixtures::filesystem();
        $this->store = new FlysystemStore('flysystem', $this->filesystem, Fixtures::factory());
    }

    public function keepsTheNameItWasGiven(): void
    {
        Assert::same($this->store->name(), 'flysystem');
    }

    #[DataProvider('invalidNameProvider')]
    public function rejectsAnInvalidStoreName(string $name): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid store name');

        new FlysystemStore($name, $this->filesystem, Fixtures::factory());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'with a slash' => ['a/b'];
        yield 'leading dash' => ['-a'];
        yield 'over 64 characters' => [str_repeat('a', 65)];
        yield 'a trailing newline' => ["flysystem\n"];
    }

    public function writesTheUploadAndReportsWhereItWent(): void
    {
        $result = $this->store->write(
            upload: Fixtures::upload('hello'),
            groupName: 'common',
            pathGenerator: new RandomPathGenerator(),
            mediaType: 'text/plain',
        );

        Assert::same($result->size, 5);
        Assert::true($result->created);
        Assert::true($this->filesystem->fileExists($result->relativePath));
        Assert::same($this->filesystem->read($result->relativePath), 'hello');
        // an object store addresses by key, and the key is the path
        Assert::same($result->externalId, $result->relativePath);
    }

    /**
     * The wrapper exists so Flysystem reads the upload once, straight through.
     * A body larger than any sane buffer proves it is not being materialised.
     */
    public function streamsWithoutBufferingTheWholeBody(): void
    {
        $contents = str_repeat('x', 3_000_000);

        $result = $this->store->write(
            upload: Fixtures::upload($contents),
            groupName: 'common',
            pathGenerator: new RandomPathGenerator(),
        );

        Assert::same($result->size, 3_000_000);
        Assert::same(strlen($this->filesystem->read($result->relativePath)), 3_000_000);
    }

    /**
     * A generated path that already exists is an error, never sharing: the
     * caller asked for a new object, and returning somebody else's ties two
     * logical files to bytes only one of them owns.
     */
    public function aPathCollisionIsAnErrorRatherThanSilentSharing(): void
    {
        $generator = new FixedPathGenerator('common/fixed/original.txt');
        $this->store->write(Fixtures::upload(), 'common', $generator);

        Expect::exception(StoreException::class)->withMessageContaining('already exists in store "flysystem"');

        $this->store->write(Fixtures::upload(), 'common', $generator);
    }

    /**
     * A remote PUT cannot be aborted halfway, so a known-oversized upload is
     * refused before any bytes leave the process.
     */
    public function aKnownOversizedUploadIsRefusedBeforeAnyWrite(): void
    {
        Expect::exception(UploadTooLargeException::class)->withMessageContaining('exceeds the 3 byte limit');

        $this->store->write(
            upload: Fixtures::upload('hello'),
            groupName: 'common',
            pathGenerator: new RandomPathGenerator(),
            maxBytes: 3,
        );
    }

    public function nothingIsLeftBehindWhenTheCapIsRefused(): void
    {
        try {
            $this->store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator(), maxBytes: 3);
        } catch (UploadTooLargeException) {
            // asserted in its own test
        }

        Assert::same(iterator_to_array($this->store->objects(), false), []);
    }

    /**
     * The second half of the cap: a body that under-reported its size is
     * caught after the write, and the object is removed rather than left for
     * a metadata row to point at.
     */
    public function anUploadThatLiedAboutItsSizeIsRemovedAfterTheWrite(): void
    {
        $factory = Fixtures::factory();
        $upload = \Rasuvaeff\Yii3Filestorage\Upload::fromStream(
            new UnsizedStream($factory->createStream('hello')),
            'a.txt',
            $factory,
        );

        try {
            $this->store->write($upload, 'common', new RandomPathGenerator(), maxBytes: 3);
            Assert::fail('the cap should have refused this upload');
        } catch (UploadTooLargeException $e) {
            Assert::string($e->getMessage())->contains('exceeds the 3 byte limit');
        }

        // the object really is gone, not merely reported as refused
        Assert::same(iterator_to_array($this->store->objects(), false), []);
    }

    /**
     * A body exactly at the cap is allowed. Off-by-one here rejects the
     * uploads a group's limit was written to permit.
     */
    public function abodyExactlyAtTheCapIsAccepted(): void
    {
        $result = $this->store->write(
            upload: Fixtures::upload('hello'),
            groupName: 'common',
            pathGenerator: new RandomPathGenerator(),
            maxBytes: 5,
        );

        Assert::same($result->size, 5);
    }

    public function anUnknownSizedBodyExactlyAtTheCapIsAccepted(): void
    {
        $factory = Fixtures::factory();
        $upload = Upload::fromStream(new UnsizedStream($factory->createStream('hello')), 'a.txt', $factory);

        $result = $this->store->write($upload, 'common', new RandomPathGenerator(), maxBytes: 5);

        Assert::same($result->size, 5);
    }

    /**
     * Zero means no cap, not a cap of zero — every upload would otherwise be
     * refused by a group that simply did not configure a limit.
     */
    public function zeroMeansNoCapAtAll(): void
    {
        $result = $this->store->write(
            upload: Fixtures::upload('hello'),
            groupName: 'common',
            pathGenerator: new RandomPathGenerator(),
            maxBytes: 0,
        );

        Assert::same($result->size, 5);
    }

    public function anEmptyBodyIsStoredRatherThanRefused(): void
    {
        $result = $this->store->write(Fixtures::upload(''), 'common', new RandomPathGenerator());

        Assert::same($result->size, 0);
        Assert::same($this->filesystem->read($result->relativePath), '');
    }

    public function readsBackWhatItWrote(): void
    {
        $result = $this->store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());
        $file = Fixtures::file(relativePath: $result->relativePath);

        Assert::true($this->store->exists($file));
        Assert::same($this->store->size($file), 5);
        Assert::same((string) $this->store->stream($file)?->getContents(), 'hello');
        Assert::true($this->store->lastModified($file) instanceof DateTimeImmutable);
    }

    public function readsOfAMissingObjectAnswerWithNull(): void
    {
        $file = Fixtures::file(relativePath: 'nothing/here/original.txt');

        Assert::false($this->store->exists($file));
        Assert::null($this->store->size($file));
        Assert::null($this->store->lastModified($file));
        Assert::null($this->store->stream($file));
    }

    /**
     * Deleting removes the file's whole directory. On an object store that is
     * a prefix, and everything under it — the derivatives a preview package
     * wrote beside the original — has to go with it, or it leaks forever and
     * indistinguishably from live data.
     */
    public function deleteRemovesTheDirectoryIncludingSiblings(): void
    {
        $result = $this->store->write(Fixtures::upload(), 'common', new FixedPathGenerator('g/key/original.txt'));
        $this->filesystem->write('g/key/thumb.webp', 'preview');
        $this->filesystem->write('g/other/original.txt', 'untouched');

        $this->store->delete(Fixtures::file(relativePath: $result->relativePath));

        Assert::false($this->filesystem->fileExists('g/key/original.txt'));
        Assert::false($this->filesystem->fileExists('g/key/thumb.webp'));
        Assert::true($this->filesystem->fileExists('g/other/original.txt'));
    }

    public function theInventoryPagesAndResumes(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->filesystem->write("g/{$key}/original.txt", $key);
        }

        $first = array_map(
            static fn(StoredObjectId $o): string => $o->relativePath,
            iterator_to_array($this->store->objects(limit: 2), false),
        );
        $rest = array_map(
            static fn(StoredObjectId $o): string => $o->relativePath,
            iterator_to_array($this->store->objects(afterPath: 'g/b/original.txt'), false),
        );

        Assert::same($first, ['g/a/original.txt', 'g/b/original.txt']);
        Assert::same($rest, ['g/c/original.txt']);
    }

    /**
     * A retried collection pass has to converge, not fail on the second
     * attempt at an object the first one already removed.
     */
    public function deleteObjectRemovesOneObjectAndIsIdempotent(): void
    {
        $this->filesystem->write('g/a/original.txt', 'a');
        $object = new StoredObjectId('g/a/original.txt');

        $this->store->deleteObject($object);
        $this->store->deleteObject($object);

        Assert::false($this->filesystem->fileExists('g/a/original.txt'));
    }

    /**
     * The in-memory adapter has no URL generator, which is the common case for
     * everything that is not S3 — and the honest answer is null, not a throw
     * and not a guess.
     */
    public function anAdapterWithoutUrlSupportAnswersWithNull(): void
    {
        $file = Fixtures::file();

        Assert::null($this->store->publicUrl($file));
        Assert::null($this->store->temporaryUrl($file, new DateTimeImmutable('+1 hour'), Fixtures::deliveryOptions()));
    }

    /**
     * A presigned URL that cannot carry the delivery policy is worse than no
     * presigned URL — it would serve an uploaded HTML file inline from your
     * bucket. Without a mapping, the store declines and the caller falls back
     * to the application proxy, which enforces the headers itself.
     */
    public function withoutAnOptionsMapperNoTemporaryUrlIsIssued(): void
    {
        $store = new FlysystemStore('flysystem', $this->urlCapable(new StubUrlGenerator()), Fixtures::factory());

        Assert::same($store->publicUrl(Fixtures::file()), 'https://cdn.example.com/common/ab/cd/key/original.txt');
        Assert::null(
            $store->temporaryUrl(Fixtures::file(), new DateTimeImmutable('+1 hour'), Fixtures::deliveryOptions()),
        );
    }

    public function withAnOptionsMapperTheDeliveryPolicyReachesTheUrl(): void
    {
        $generator = new StubUrlGenerator();
        $store = new FlysystemStore(
            'flysystem',
            $this->urlCapable($generator),
            Fixtures::factory(),
            new S3TemporaryUrlOptions(),
        );

        $url = $store->temporaryUrl(Fixtures::file(), new DateTimeImmutable('+1 hour'), Fixtures::deliveryOptions());

        Assert::same($url, 'https://cdn.example.com/common/ab/cd/key/original.txt?signed');
        Assert::same(
            $generator->lastConfig['get_object_options']['ResponseContentDisposition'] ?? null,
            'attachment; filename="a.txt"; filename*=UTF-8\'\'a.txt',
        );
    }

    /**
     * Flysystem raises these when no generator is configured for the adapter,
     * and they are a capability answer rather than a failure.
     */
    public function generatorFailuresBecomeNullRatherThanExceptions(): void
    {
        $store = new FlysystemStore(
            'flysystem',
            $this->urlCapable(new StubUrlGenerator(throwing: true)),
            Fixtures::factory(),
            new S3TemporaryUrlOptions(),
        );

        Assert::null($store->publicUrl(Fixtures::file()));
        Assert::null(
            $store->temporaryUrl(Fixtures::file(), new DateTimeImmutable('+1 hour'), Fixtures::deliveryOptions()),
        );
    }

    /**
     * Not only the dedicated URL exceptions: an adapter that fails while
     * generating — a network error inside a signing call — is still a
     * capability answer, not something to propagate out of a URL getter.
     */
    public function anAdapterFailureDuringGenerationAlsoBecomesNull(): void
    {
        $store = new FlysystemStore(
            'flysystem',
            $this->urlCapable(new StubUrlGenerator(failing: true)),
            Fixtures::factory(),
            new S3TemporaryUrlOptions(),
        );

        Assert::null($store->publicUrl(Fixtures::file()));
        Assert::null(
            $store->temporaryUrl(Fixtures::file(), new DateTimeImmutable('+1 hour'), Fixtures::deliveryOptions()),
        );
    }

    /**
     * An operator that answers with an empty string has not produced a URL.
     * Passing that through hands the caller a link to the current page.
     */
    public function anEmptyUrlIsNotAUrl(): void
    {
        $store = new FlysystemStore(
            'flysystem',
            $this->urlCapable(new StubUrlGenerator(empty: true)),
            Fixtures::factory(),
            new S3TemporaryUrlOptions(),
        );

        Assert::null($store->publicUrl(Fixtures::file()));
        Assert::null(
            $store->temporaryUrl(Fixtures::file(), new DateTimeImmutable('+1 hour'), Fixtures::deliveryOptions()),
        );
    }

    /**
     * The capabilities this store claims, and the two it deliberately does
     * not. Range is absent because Flysystem has no range primitive and an
     * S3 body is not seekable — implementing it by discarding a prefix would
     * advertise cheap seeking and deliver a full download.
     */
    public function itClaimsExactlyTheCapabilitiesItCanHonour(): void
    {
        Assert::instanceOf($this->store, StoreUrlProviderInterface::class);
        Assert::instanceOf($this->store, MaintenanceStoreInterface::class);
        Assert::false($this->store instanceof RangeReadableStoreInterface);
        Assert::false($this->store instanceof ContentAddressableStoreInterface);
    }

    private function urlCapable(StubUrlGenerator $generator): Filesystem
    {
        return new Filesystem(
            adapter: new \League\Flysystem\InMemory\InMemoryFilesystemAdapter(),
            publicUrlGenerator: $generator,
            temporaryUrlGenerator: $generator,
        );
    }

    /**
     * Null means the object is not there. A transport failure is not that —
     * and swallowing every FilesystemException made a reset connection, an
     * expired credential and a throttling response indistinguishable from a
     * missing key. The download action reads null as "gone" and answers 404 for
     * a live file; verify reports a whole directory as missing during an
     * outage.
     */
    public function anUnreachableStoreIsNotAMissingObject(): void
    {
        $store = new FlysystemStore('flysystem', new UnreachableFilesystem(), Fixtures::factory());

        Expect::exception(StoreException::class)->withMessageContaining('could not be reached');

        $store->stream(Fixtures::file());
    }

    /**
     * The third case, and the one silence hid best: the store answers, the
     * object is there, and the read still failed. Reporting that as "missing"
     * sends an operator looking for a lost file that is not lost.
     */
    public function anUnreadablePresentObjectIsNotAMissingOne(): void
    {
        $store = new FlysystemStore(
            'flysystem',
            new UnreachableFilesystem(existenceAnswers: true),
            Fixtures::factory(),
        );

        Expect::exception(StoreException::class)->withMessageContaining('but could not be read');

        $store->stream(Fixtures::file());
    }

    public function aGenuinelyMissingObjectStillStreamsAsNull(): void
    {
        Assert::null($this->store->stream(Fixtures::file(relativePath: 'nothing/here/original.bin')));
    }

    /**
     * sortByPath() is toArray() + usort(), so it materialises and orders the
     * whole bucket before the first yield — and gc calls objects() once per
     * page. Declaring the adapter already ordered skips it; the listing must
     * come out the same either way.
     */
    public function anOrderedAdapterIsNotSortedAgain(): void
    {
        foreach (['b/original.bin', 'a/original.bin', 'c/original.bin'] as $path) {
            $this->filesystem->write($path, 'x');
        }

        $declared = new FlysystemStore(
            'flysystem',
            $this->filesystem,
            Fixtures::factory(),
            semantics: AdapterSemantics::guaranteed(orderedListing: true),
        );

        $paths = array_map(
            static fn(StoredObjectId $id): string => $id->relativePath,
            iterator_to_array($declared->objects(), false),
        );
        sort($paths);

        Assert::same($paths, ['a/original.bin', 'b/original.bin', 'c/original.bin']);
    }
}
