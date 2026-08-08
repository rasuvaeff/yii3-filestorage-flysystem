<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemContentAddressableStore;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\UnsizedStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FlysystemContentAddressableStore::class)]
final class FlysystemContentAddressableStoreTest
{
    private const string KEY = 'sha/e3/b0/e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855/original';

    private Filesystem $filesystem;
    private FlysystemContentAddressableStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->filesystem = Fixtures::filesystem();
        $this->store = new FlysystemContentAddressableStore(
            store: new FlysystemStore('flysystem', $this->filesystem, Fixtures::factory()),
            filesystem: $this->filesystem,
            semantics: AdapterSemantics::guaranteed(),
        );
    }

    public function itIsTheCapabilityTheLedgerLooksFor(): void
    {
        Assert::instanceOf($this->store, ContentAddressableStoreInterface::class);
        Assert::true($this->store->semantics()->atomicVisibility);
    }

    public function aFirstPutPublishesTheBytes(): void
    {
        $result = $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY));

        Assert::true($result->created);
        Assert::same($result->size, 5);
        Assert::same($result->relativePath, self::KEY);
        Assert::same($this->filesystem->read(self::KEY), 'hello');
    }

    /**
     * The point of the whole exercise: the second writer of identical content
     * does not write, and says so, so the ledger records a reference instead of
     * a new object.
     */
    public function aSecondPutReusesThemAndSaysSo(): void
    {
        $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY));

        $result = $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY));

        Assert::false($result->created);
        Assert::same($result->size, 5);
    }

    /**
     * The key is the hash of the content, so a different length at that key
     * means something that does not share this convention wrote it. Reusing
     * those bytes would hand the caller somebody else's file; overwriting them
     * would take it away from whoever already references it.
     */
    public function bytesOfTheWrongLengthAtAContentKeyAreAHardError(): void
    {
        $this->filesystem->write(self::KEY, 'not the same content at all');

        // spans the concatenation on purpose: asserting one half lets the operands swap undetected
        Expect::exception(StoreException::class)->withMessageContaining(
            'holds 27 bytes but the upload is 5. Something outside this package wrote that key',
        );

        $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY));
    }

    public function aKnownOversizedUploadIsRefusedBeforeAnyWrite(): void
    {
        Expect::exception(UploadTooLargeException::class)->withMessageContaining('exceeds the 3 byte limit');

        $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY), maxBytes: 3);
    }

    /**
     * At the cap, not over it. And zero is no cap, not a cap of zero.
     */
    public function abodyExactlyAtTheCapIsAccepted(): void
    {
        $result = $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY), maxBytes: 5);

        Assert::true($result->created);
        Assert::same($result->size, 5);
    }

    public function zeroMeansNoCapAtAll(): void
    {
        $result = $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY), maxBytes: 0);

        Assert::same($result->size, 5);
    }

    /**
     * The cap is verified after the write too, for a body that under-reported.
     * Unlike a unique write the object is *not* removed: another writer may
     * already reference it, so reclaiming it is the collector's decision.
     */
    public function anUploadThatLiedAboutItsSizeIsRefusedButLeftForTheCollector(): void
    {
        $factory = Fixtures::factory();
        $upload = Upload::fromStream(new UnsizedStream($factory->createStream('hello')), 'a.bin', $factory);

        try {
            $this->store->putIfAbsent($upload, new StoredObjectId(self::KEY), maxBytes: 3);
            Assert::fail('the cap should have refused this upload');
        } catch (UploadTooLargeException $e) {
            Assert::string($e->getMessage())->contains('exceeds the 3 byte limit');
        }

        Assert::true($this->filesystem->fileExists(self::KEY));
    }

    public function nothingIsWrittenWhenTheCapRefusesTheUpload(): void
    {
        try {
            $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY), maxBytes: 3);
        } catch (UploadTooLargeException) {
            // asserted in its own test
        }

        Assert::false($this->filesystem->fileExists(self::KEY));
    }

    /**
     * Every non-dedup operation is the base store's, and delegation has to be
     * complete — a store that answered differently through this class than
     * through the plain one would make the dedup binding change behaviour it
     * has no business changing.
     */
    public function everythingElseBehavesLikeThePlainStore(): void
    {
        $result = $this->store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());
        $file = Fixtures::file(relativePath: $result->relativePath);

        Assert::same($this->store->name(), 'flysystem');
        Assert::true($this->store->exists($file));
        Assert::same($this->store->size($file), 5);
        Assert::same((string) $this->store->stream($file)?->getContents(), 'hello');
        Assert::true($this->store->lastModified($file) instanceof DateTimeImmutable);
        Assert::null($this->store->publicUrl($file));
        Assert::null(
            $this->store->temporaryUrl($file, new DateTimeImmutable('+1 hour'), Fixtures::deliveryOptions()),
        );
        Assert::same(\count(iterator_to_array($this->store->objects(), false)), 1);

        $this->store->deleteObject(new StoredObjectId($result->relativePath));
        Assert::false($this->store->exists($file));
    }

    public function deleteRemovesTheWholeDirectory(): void
    {
        $result = $this->store->write(Fixtures::upload(), 'common', new RandomPathGenerator());
        $file = Fixtures::file(relativePath: $result->relativePath);

        $this->store->delete($file);

        Assert::false($this->filesystem->fileExists($result->relativePath));
    }

    /**
     * The contract says bytes are reused only after a size check, and "the
     * upload did not declare a length" is the ordinary case for a stream — so
     * skipping the check there made the guarantee vacuous exactly where it was
     * needed. The size is counted instead; this branch transfers nothing, so
     * counting stays far cheaper than the upload it avoids.
     */
    public function reuseChecksTheSizeEvenWhenTheUploadDoesNotDeclareOne(): void
    {
        $this->filesystem->write(self::KEY, 'not the same bytes at all');

        Expect::exception(StoreException::class)->withMessageContaining('Something outside this package wrote');

        $this->store->putIfAbsent($this->unsizedUpload('hello'), new StoredObjectId(self::KEY));
    }

    public function anUnsizedUploadStillReusesMatchingBytes(): void
    {
        $this->filesystem->write(self::KEY, 'hello');

        $result = $this->store->putIfAbsent($this->unsizedUpload('hello'), new StoredObjectId(self::KEY));

        Assert::false($result->created);
        Assert::same($result->size, 5);
    }

    /**
     * The cap is about what this caller may store, not about how the bytes got
     * there. Reusing past it would let a group's limit be bypassed by anything
     * that uploaded the same content under a laxer one.
     */
    public function reuseIsRefusedWhenTheExistingObjectExceedsTheCap(): void
    {
        $this->filesystem->write(self::KEY, 'hello');

        Expect::exception(UploadTooLargeException::class)->withMessageContaining('exceeds the 2 byte limit');

        $this->store->putIfAbsent(Fixtures::upload('hello'), new StoredObjectId(self::KEY), maxBytes: 2);
    }

    private function unsizedUpload(string $contents): Upload
    {
        return Upload::fromStream(
            new UnsizedStream(Fixtures::factory()->createStream($contents)),
            'a.txt',
            Fixtures::factory(),
        );
    }
}
