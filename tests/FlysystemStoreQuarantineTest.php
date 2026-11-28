<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\Visibility;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\FaultyFilesystem;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\FixedPathGenerator;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * Two-phase deletion: objects leave their key for a private prefix, and come
 * back — without overwriting a re-publication — or are purged.
 */
#[Test]
#[Covers(FlysystemStore::class)]
final class FlysystemStoreQuarantineTest
{
    private const string PREFIX = '.quarantine';

    private FaultyFilesystem $filesystem;
    private FlysystemStore $store;
    private DateTimeImmutable $now;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->filesystem = new FaultyFilesystem(new InMemoryFilesystemAdapter());
        $this->store = $this->store();
        $this->now = new DateTimeImmutable('@1700000000');
    }

    #[DataProvider('validPrefixProvider')]
    public function acceptsAPrefixNoGeneratedKeyCanReach(string $prefix): void
    {
        Assert::same($this->store(prefix: $prefix)->quarantinePrefix(), $prefix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPrefixProvider(): iterable
    {
        yield 'dot' => ['.quarantine'];
        yield 'underscore' => ['_quarantine'];
        yield 'bare underscore' => ['_'];
        yield 'nested' => ['.trash/gc-1'];
        yield 'dotted' => ['.q.v2'];
    }

    /**
     * Empty is "around" every key. A first segment starting with a letter or
     * digit is a possible group or scope name, so the quarantine could sit
     * inside generated keys. Anything that is not a plain relative path is
     * refused outright.
     */
    #[DataProvider('invalidPrefixProvider')]
    public function rejectsAPrefixThatCouldOverlapTheKeys(string $prefix): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid quarantine prefix');

        $this->store(prefix: $prefix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPrefixProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'group-like' => ['quarantine'];
        yield 'digit' => ['1quarantine'];
        yield 'absolute' => ['/.quarantine'];
        yield 'trailing slash' => ['.quarantine/'];
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
        yield 'escaping' => ['../x'];
        yield 'double slash' => ['.q//x'];
        yield 'parent inside' => ['.q/../x'];
        yield 'hidden segment inside' => ['.q/.x'];
        yield 'reserved later' => ['q/.x'];
        yield 'backslash' => ['.q\\x'];
        yield 'newline' => [".q\n"];
        yield 'space' => ['.q x'];
    }

    public function anInvalidPrefixIsExplained(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage(
            'Invalid quarantine prefix "quarantine" for store "flysystem": a relative path whose first segment '
            . 'starts with "." or "_", so it can neither hold nor contain a generated key',
        );

        $this->store(prefix: 'quarantine');
    }

    public function aWriteNeverLandsInTheQuarantine(): void
    {
        foreach ([self::PREFIX . '/g/a/original.txt', self::PREFIX] as $path) {
            try {
                $this->store->write(Fixtures::upload(), 'common', new FixedPathGenerator($path));
                Assert::fail('a write into the quarantine should have been refused');
            } catch (StoreException $e) {
                Assert::same(
                    $e->getMessage(),
                    "Generated path \"{$path}\" lies inside the quarantine of store \"flysystem\"",
                );
            }

            Assert::false($this->filesystem->fileExists($path));
        }

        $result = $this->store->write(Fixtures::upload(), 'common', new FixedPathGenerator('.quarantined/a'));
        Assert::same($result->relativePath, '.quarantined/a');
    }

    public function aStoreWithoutAQuarantineSaysSo(): void
    {
        $store = $this->store(prefix: null);

        Assert::same(
            $store->quarantineProblem(),
            'Store "flysystem" has no quarantine prefix, so filestorage:gc cannot delete from it. '
            . 'Pass quarantinePrefix: a private prefix such as ".quarantine", excluded from any public read policy',
        );
        Assert::null($this->store->quarantineProblem());
    }

    public function aStoreWithoutAQuarantineRefusesEveryQuarantineOperation(): void
    {
        $store = $this->store(prefix: null);
        $this->filesystem->write('g/key/original.txt', 'x');
        $entry = new QuarantinedObject('1700000000-0123456789abcdef', [new StoredObjectId('g/key/original.txt')]);
        $message = 'Store "flysystem" has no quarantine prefix configured, so it cannot quarantine objects';

        foreach ([
            static fn() => $store->quarantine(ObjectSet::tree(new StoredObjectId('g/key')), new DateTimeImmutable()),
            static fn() => $store->restore($entry),
            static fn() => $store->purge($entry),
            static fn() => iterator_to_array($store->quarantined()),
        ] as $operation) {
            try {
                $operation();
                Assert::fail('an operation without a quarantine should have been refused');
            } catch (StoreException $e) {
                Assert::same($e->getMessage(), $message);
            }
        }

        Assert::true($this->filesystem->fileExists('g/key/original.txt'));
    }

    public function aKeyDirectoryRoundTripsThroughTheQuarantine(): void
    {
        $this->filesystem->write('g/key/original.txt', 'original');
        $this->filesystem->write('g/key/thumb.webp', 'thumb');
        $this->filesystem->write('g/other/original.txt', 'other');

        $entry = $this->store->quarantine(
            $this->store->layout()->deletion(new StoredObjectId('g/key/original.txt')),
            $this->now,
        );

        Assert::instanceOf($entry, QuarantinedObject::class);
        Assert::same($entry->quarantinedAt->getTimestamp(), 1_700_000_000);
        Assert::same($this->ids($entry->objects), ['g/key/original.txt', 'g/key/thumb.webp']);
        Assert::same($this->paths(), [
            self::PREFIX . "/{$entry->token}/g/key/original.txt",
            self::PREFIX . "/{$entry->token}/g/key/thumb.webp",
            'g/other/original.txt',
        ]);
        Assert::same($this->inventory(), ['g/other/original.txt']);

        $listed = iterator_to_array($this->store->quarantined(), preserve_keys: false);
        Assert::same(\count($listed), 1);
        Assert::same($listed[0]->token, $entry->token);
        Assert::same($this->ids($listed[0]->objects), ['g/key/original.txt', 'g/key/thumb.webp']);

        $this->store->restore($entry);

        Assert::same($this->paths(), ['g/key/original.txt', 'g/key/thumb.webp', 'g/other/original.txt']);
        Assert::same($this->filesystem->read('g/key/thumb.webp'), 'thumb');
        Assert::same(iterator_to_array($this->store->quarantined()), []);
    }

    public function aRootLevelObjectRoundTripsThroughTheQuarantine(): void
    {
        $this->filesystem->write('loose.txt', 'x');

        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('loose.txt')), $this->now);

        Assert::same($this->ids($entry?->objects ?? []), ['loose.txt']);
        Assert::false($this->filesystem->fileExists('loose.txt'));
    }

    public function flatSiblingsRoundTripThroughTheQuarantine(): void
    {
        $store = $this->store(layout: new FlatSiblingLayout());
        foreach (['abc.jpg', 'abc-s-v1.webp', 'abcd.jpg'] as $name) {
            $this->filesystem->write('blobs/ab/cd/' . $name, $name);
        }

        $entry = $store->quarantine(
            $store->layout()->deletion(new StoredObjectId('blobs/ab/cd/abc.jpg')),
            $this->now,
        );

        Assert::same($this->ids($entry?->objects ?? []), ['blobs/ab/cd/abc-s-v1.webp', 'blobs/ab/cd/abc.jpg']);
        Assert::same($this->inventory(), ['blobs/ab/cd/abcd.jpg']);

        \assert($entry instanceof QuarantinedObject);
        $store->restore($entry);

        Assert::same($this->inventory(), ['blobs/ab/cd/abc-s-v1.webp', 'blobs/ab/cd/abc.jpg', 'blobs/ab/cd/abcd.jpg']);
        Assert::same($this->paths(), $this->inventory());
    }

    public function quarantiningNothingIsNoEntry(): void
    {
        Assert::null($this->store->quarantine(ObjectSet::tree(new StoredObjectId('g/none')), $this->now));
        Assert::same($this->paths(), []);
    }

    /**
     * A set that would reach into the quarantine — the prefix itself as a
     * tree — must not move held objects into a new entry of themselves.
     */
    public function theQuarantineIsNeverQuarantinedItself(): void
    {
        $this->filesystem->write(self::PREFIX . '/1700000000-0123456789abcdef/g/a/original.txt', 'held');

        Assert::null($this->store->quarantine(ObjectSet::tree(new StoredObjectId(self::PREFIX)), $this->now));
        Assert::same($this->paths(), [self::PREFIX . '/1700000000-0123456789abcdef/g/a/original.txt']);
    }

    /**
     * The whole point of restore-if-absent: a writer that re-published the
     * content while it was quarantined keeps its object, and the held copy is
     * dropped with the entry.
     */
    public function aRestoreKeepsARePublication(): void
    {
        $this->filesystem->write('g/key/original.txt', 'quarantined copy');
        $entry = $this->quarantineKey();
        $this->filesystem->write('g/key/original.txt', 'republished');

        $this->store->restore($entry);
        $this->store->restore($entry);

        Assert::same($this->filesystem->read('g/key/original.txt'), 'republished');
        Assert::same($this->paths(), ['g/key/original.txt']);
    }

    /**
     * Put-if-absent is per object: one member taken again does not stop the
     * others coming back.
     */
    public function aRestoreSkipsOnlyTheMemberThatWasRePublished(): void
    {
        $this->filesystem->write('g/key/a.txt', 'held a');
        $this->filesystem->write('g/key/b.txt', 'held b');
        $entry = $this->quarantineKey();
        $this->filesystem->write('g/key/a.txt', 'republished');

        $this->store->restore($entry);

        Assert::same($this->filesystem->read('g/key/a.txt'), 'republished');
        Assert::same($this->filesystem->read('g/key/b.txt'), 'held b');
        Assert::same($this->paths(), ['g/key/a.txt', 'g/key/b.txt']);
    }

    public function aPurgeRemovesTheEntryAndIsIdempotent(): void
    {
        $this->filesystem->write('g/key/original.txt', 'x');
        $this->filesystem->write('g/other/original.txt', 'y');
        $entry = $this->quarantineKey();
        $other = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('g/other')), $this->now);
        \assert($other instanceof QuarantinedObject);

        $this->store->purge($entry);
        $this->store->purge($entry);

        Assert::same($this->paths(), [self::PREFIX . "/{$other->token}/g/other/original.txt"]);
        $listed = iterator_to_array($this->store->quarantined(), preserve_keys: false);
        Assert::same(array_map(static fn(QuarantinedObject $e): string => $e->token, $listed), [$other->token]);
    }

    /**
     * Listing costs requests on S3. A key directory is one deep listing of its
     * prefix, flat siblings one shallow listing of their directory — a deep
     * one there would walk every subdirectory to find nothing — and the
     * quarantine one shallow listing for the tokens plus one deep listing per
     * entry.
     */
    public function everyListingIsAsNarrowAsTheLayoutAllows(): void
    {
        $this->filesystem->write('g/key/a.txt', 'a');
        $entry = $this->quarantineKey();
        $flat = $this->store(layout: new FlatSiblingLayout());
        $this->filesystem->write('blobs/ab/abc.jpg', 'x');
        $flat->quarantine(
            $flat->layout()->deletion(new StoredObjectId('blobs/ab/abc.jpg')),
            new DateTimeImmutable('@1700000001'),
        );
        $this->filesystem->listings = [];

        $this->store->quarantine(ObjectSet::tree(new StoredObjectId('g/none')), $this->now);
        $flat->quarantine($flat->layout()->deletion(new StoredObjectId('blobs/ab/abc.jpg')), $this->now);
        iterator_to_array($this->store->quarantined(limit: 1));

        Assert::same($this->filesystem->listings, [
            ['g/none', true],
            ['blobs/ab', false],
            [self::PREFIX, false],
            [self::PREFIX . "/{$entry->token}/", true],
        ]);
    }

    /**
     * Some adapters report a directory with its trailing slash. The token is
     * the name without it — otherwise no entry is ever found again, and
     * nothing is restored or purged. An emptied entry is skipped, not the end
     * of the listing.
     */
    public function anEntryListedWithATrailingSlashIsStillFound(): void
    {
        $filesystem = Understudy::strict(Understudy::for(FilesystemOperator::class));
        when(fn() => $filesystem->listContents(self::PREFIX, false))
            ->answers(static fn(): DirectoryListing => new DirectoryListing([
                new DirectoryAttributes(self::PREFIX . '/1700000001-0000000000000000/'),
                new DirectoryAttributes(self::PREFIX . '/1700000002-0000000000000000/'),
            ]));
        when(fn() => $filesystem->listContents(self::PREFIX . '/1700000001-0000000000000000/', true))
            ->answers(static fn(): DirectoryListing => new DirectoryListing([]));
        when(fn() => $filesystem->listContents(self::PREFIX . '/1700000002-0000000000000000/', true))
            ->answers(static fn(): DirectoryListing => new DirectoryListing([
                new FileAttributes(self::PREFIX . '/1700000002-0000000000000000/g/a.txt'),
            ]));
        $store = new FlysystemStore('flysystem', $filesystem, Fixtures::factory(), quarantinePrefix: self::PREFIX);

        $listed = iterator_to_array($store->quarantined(), preserve_keys: false);

        Assert::same(\count($listed), 1);
        Assert::same($listed[0]->token, '1700000002-0000000000000000');
        Assert::same($this->ids($listed[0]->objects), ['g/a.txt']);
    }

    /**
     * Flysystem's S3 copy carries the source's ACL along by default, so a
     * deleted public object would stay downloadable from the quarantine for
     * the whole retention period.
     */
    public function aQuarantinedObjectIsPrivate(): void
    {
        $this->filesystem->write('g/key/original.txt', 'x', [Config::OPTION_VISIBILITY => Visibility::PUBLIC]);

        $entry = $this->quarantineKey();

        Assert::same(
            $this->filesystem->visibility(self::PREFIX . "/{$entry->token}/g/key/original.txt"),
            Visibility::PRIVATE,
        );
    }

    /**
     * Restored, an object looks like a fresh write — the filesystem's default
     * visibility — not like the private copy it was in the quarantine.
     */
    public function aRestoredObjectGetsTheDefaultVisibility(): void
    {
        $this->filesystem = new FaultyFilesystem(
            new InMemoryFilesystemAdapter(),
            [Config::OPTION_VISIBILITY => Visibility::PUBLIC],
        );
        $this->store = $this->store();
        $this->filesystem->write('g/key/original.txt', 'x');

        $this->store->restore($this->quarantineKey());

        Assert::same($this->filesystem->visibility('g/key/original.txt'), Visibility::PUBLIC);
    }

    public function theQuarantinePagesInTokenOrder(): void
    {
        $tokens = [];
        foreach ([3, 1, 2] as $second) {
            $this->filesystem->write("g/k{$second}/original.txt", 'x');
            $entry = $this->store->quarantine(
                ObjectSet::tree(new StoredObjectId("g/k{$second}")),
                new DateTimeImmutable('@' . (1_700_000_000 + $second)),
            );
            \assert($entry instanceof QuarantinedObject);
            $tokens[$second] = $entry->token;
        }
        $this->filesystem->write(self::PREFIX . '/not-a-token/g/x/original.txt', 'stray');
        $this->filesystem->write(self::PREFIX . '/1700000009-0123456789abcdef', 'a file, not an entry');
        // An entry emptied by a crash between moves is not an entry, and must
        // not end the page either: it sorts between the first two.
        $this->filesystem->createDirectory(self::PREFIX . '/1700000001-ffffffffffffffff');

        $first = iterator_to_array($this->store->quarantined(limit: 2), preserve_keys: false);
        $rest = iterator_to_array($this->store->quarantined(afterToken: $tokens[2]), preserve_keys: false);

        Assert::same(array_map(static fn(QuarantinedObject $e): string => $e->token, $first), [$tokens[1], $tokens[2]]);
        Assert::same(array_map(static fn(QuarantinedObject $e): string => $e->token, $rest), [$tokens[3]]);
        Assert::same($this->ids($rest[0]->objects), ['g/k3/original.txt']);
    }

    public function anEntryCannotBeRestoredIntoTheQuarantine(): void
    {
        $entry = new QuarantinedObject(
            '1700000000-0123456789abcdef',
            [new StoredObjectId(self::PREFIX . '/1600000000-0123456789abcdef/g/a/original.txt')],
        );

        Expect::exception(StoreException::class)->withMessageContaining('Cannot restore');

        $this->store->restore($entry);
    }

    /**
     * Gone between the listing and the move — another deleter got there
     * first. Like a local rename of a vanished file, that member is skipped.
     */
    public function aMemberThatVanishedBeforeItsMoveIsSkipped(): void
    {
        $this->filesystem->write('g/key/a.txt', 'a');
        $this->filesystem->write('g/key/b.txt', 'b');
        $filesystem = $this->filesystem;
        $this->filesystem->on('move', static function (string $source) use ($filesystem): void {
            if ($source === 'g/key/a.txt') {
                $filesystem->delete($source);

                throw UnableToMoveFile::fromLocationTo($source, 'elsewhere');
            }
        });

        $entry = $this->quarantineKey();

        Assert::same($this->ids($entry->objects), ['g/key/b.txt']);
    }

    public function everyMemberVanishingIsNoEntry(): void
    {
        $this->filesystem->write('g/key/a.txt', 'a');
        $filesystem = $this->filesystem;
        $this->filesystem->on('move', static function (string $source) use ($filesystem): never {
            $filesystem->delete($source);

            throw UnableToMoveFile::fromLocationTo($source, 'elsewhere');
        });

        Assert::null($this->store->quarantine(ObjectSet::tree(new StoredObjectId('g/key')), $this->now));
    }

    /**
     * A move that failed with the object still there puts back what moved
     * before it (core 0.10 contract, review 1.0 B8): a half-quarantined set
     * whose row stays would have the moved half purged by the retention.
     */
    public function aFailedMovePutsBackWhatAlreadyMoved(): void
    {
        $this->filesystem->write('g/key/a.txt', 'a');
        $this->filesystem->write('g/key/b.txt', 'b');
        $cause = UnableToMoveFile::fromLocationTo('g/key/b.txt', 'elsewhere');
        $this->filesystem->on('move', static function (string $source) use ($cause): void {
            if ($source === 'g/key/b.txt') {
                throw $cause;
            }
        });

        // "nothing was moved" was untrue: a moved and came back (review 1.0
        // pass 2, N12).
        $this->assertStoreFailure(
            fn() => $this->quarantineKey(),
            'Could not quarantine "g/key/b.txt" in store "flysystem"; moved back: g/key/a.txt',
            $cause,
        );

        Assert::same($this->filesystem->read('g/key/a.txt'), 'a');
        Assert::same($this->filesystem->read('g/key/b.txt'), 'b');
        Assert::same(iterator_to_array($this->store->quarantined(), preserve_keys: false), []);
        Assert::same(iterator_to_array($this->filesystem->listContents(self::PREFIX, deep: true), preserve_keys: false), []);
        Assert::same($this->inventory(), ['g/key/a.txt', 'g/key/b.txt']);
    }

    /**
     * A member that cannot go back — its move back fails, or its key was
     * re-published meanwhile — stays under the entry, named in the message,
     * where the next gc run finds it.
     */
    public function aMemberThatCannotGoBackStaysInTheQuarantine(): void
    {
        $this->filesystem->write('g/key/a.txt', 'a');
        $this->filesystem->write('g/key/b.txt', 'b');
        $this->filesystem->write('g/key/c.txt', 'c');
        $cause = UnableToMoveFile::fromLocationTo('g/key/c.txt', 'elsewhere');
        $filesystem = $this->filesystem;
        $this->filesystem->on('move', static function (string $source) use ($cause, $filesystem): void {
            if ($source === 'g/key/c.txt') {
                // S3's move is a copy and a delete: the copy landed, the
                // delete failed — a partial copy of c sits in the entry too
                $entry = iterator_to_array($filesystem->listContents(self::PREFIX), preserve_keys: false)[0]->path();
                $filesystem->write($entry . '/g/key/c.txt', 'c');
                // a writer re-publishes a while c fails, and b cannot move back
                $filesystem->write('g/key/a.txt', 'again');
                $filesystem->on('move', static function (string $source): void {
                    if (str_ends_with($source, '/g/key/b.txt')) {
                        throw UnableToMoveFile::fromLocationTo($source, 'g/key/b.txt');
                    }
                });

                throw $cause;
            }
        });

        $this->assertStoreFailure(
            fn() => $this->quarantineKey(),
            'Could not quarantine "g/key/c.txt" in store "flysystem"; left in the quarantine, restored by gc while '
            . 'a row references them: g/key/a.txt, g/key/b.txt; the entry also holds a copy of g/key/c.txt, whose '
            . 'original is in place',
            $cause,
        );

        $listed = iterator_to_array($this->store->quarantined(), preserve_keys: false);
        Assert::same($this->ids($listed[0]->objects), ['g/key/a.txt', 'g/key/b.txt', 'g/key/c.txt']);
        Assert::same($this->filesystem->read('g/key/a.txt'), 'again');
        Assert::same($this->filesystem->read('g/key/c.txt'), 'c');
    }

    /**
     * Put back with the filesystem's default visibility, like restore(), not
     * the private one the quarantine gave it.
     */
    public function aMemberPutBackDoesNotKeepTheQuarantineVisibility(): void
    {
        $this->filesystem = new FaultyFilesystem(
            new InMemoryFilesystemAdapter(),
            [Config::OPTION_VISIBILITY => Visibility::PUBLIC],
        );
        $this->store = $this->store();
        $this->filesystem->write('g/key/a.txt', 'a');
        $this->filesystem->write('g/key/b.txt', 'b');
        $this->filesystem->on('move', static function (string $source): void {
            if ($source === 'g/key/b.txt') {
                throw UnableToMoveFile::fromLocationTo($source, 'elsewhere');
            }
        });

        try {
            $this->quarantineKey();
        } catch (StoreException) {
        }

        Assert::same($this->filesystem->visibility('g/key/a.txt'), Visibility::PUBLIC);
    }

    public function anUnreachableStoreAfterAFailedMoveIsAFailure(): void
    {
        $this->filesystem->write('g/key/a.txt', 'a');
        $cause = UnableToMoveFile::fromLocationTo('g/key/a.txt', 'elsewhere');
        $this->filesystem->on('move', static function () use ($cause): never {
            throw $cause;
        });
        $filesystem = $this->filesystem;
        $this->filesystem->on('listContents', static function () use ($filesystem): void {
            $filesystem->on('fileExists', static function (string $location): never {
                throw UnableToCheckExistence::forLocation($location);
            });
        });

        $this->assertStoreFailure(
            fn() => $this->quarantineKey(),
            'Could not quarantine "g/key/a.txt" in store "flysystem"; nothing was moved',
            $cause,
        );
    }

    public function aFailedListingOfTheSetIsAStoreFailure(): void
    {
        $cause = UnableToListContents::atLocation('g/key', deep: true, previous: new \RuntimeException('timeout'));
        $this->filesystem->on('listContents', static fn(): never => throw $cause);

        $this->assertStoreFailure(
            fn() => $this->quarantineKey(),
            'Could not list "g/key" in store "flysystem"',
            $cause,
        );
    }

    public function aFailedRestoreIsAStoreFailure(): void
    {
        $this->filesystem->write('g/key/original.txt', 'x');
        $entry = $this->quarantineKey();
        $cause = UnableToMoveFile::fromLocationTo('held', 'g/key/original.txt');
        $this->filesystem->on('move', static fn(): never => throw $cause);

        $this->assertStoreFailure(
            fn() => $this->store->restore($entry),
            'Could not restore "g/key/original.txt" in store "flysystem"',
            $cause,
        );
        Assert::same(\count(iterator_to_array($this->store->quarantined())), 1);
    }

    public function aFailedPurgeIsAStoreFailure(): void
    {
        $this->filesystem->write('g/key/original.txt', 'x');
        $entry = $this->quarantineKey();
        $cause = UnableToDeleteDirectory::atLocation('held', 'denied');
        $this->filesystem->on('deleteDirectory', static fn(): never => throw $cause);

        $this->assertStoreFailure(
            fn() => $this->store->purge($entry),
            "Could not purge quarantine entry \"{$entry->token}\" of store \"flysystem\"",
            $cause,
        );
    }

    public function aFailedQuarantineListingIsAStoreFailure(): void
    {
        $cause = UnableToListContents::atLocation(self::PREFIX, deep: false, previous: new \RuntimeException('x'));
        $this->filesystem->on('listContents', static fn(): never => throw $cause);

        $this->assertStoreFailure(
            fn() => iterator_to_array($this->store->quarantined()),
            'Could not list the quarantine of store "flysystem"',
            $cause,
        );
    }

    private function store(
        ?string $prefix = self::PREFIX,
        ?FlatSiblingLayout $layout = null,
    ): FlysystemStore {
        return new FlysystemStore(
            'flysystem',
            $this->filesystem,
            Fixtures::factory(),
            quarantinePrefix: $prefix,
            layout: $layout,
        );
    }

    private function quarantineKey(): QuarantinedObject
    {
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('g/key')), $this->now);
        \assert($entry instanceof QuarantinedObject);

        return $entry;
    }

    /**
     * @param list<StoredObjectId> $objects
     *
     * @return list<string>
     */
    private function ids(array $objects): array
    {
        return array_map(static fn(StoredObjectId $id): string => $id->relativePath, $objects);
    }

    /**
     * Everything on the filesystem, quarantine included.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = [];
        foreach ($this->filesystem->listContents('', deep: true) as $attributes) {
            if ($attributes->isFile()) {
                $paths[] = $attributes->path();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * What the store reports as its objects.
     *
     * @return list<string>
     */
    private function inventory(): array
    {
        $paths = [];
        foreach ($this->store->objects() as $object) {
            $paths[] = $object->id->relativePath;
        }
        sort($paths);

        return $paths;
    }

    /**
     * @param \Closure(): mixed $action
     */
    private function assertStoreFailure(\Closure $action, string $message, \Throwable $cause): void
    {
        try {
            $action();
        } catch (StoreException $e) {
            Assert::same($e->getMessage(), $message);
            Assert::same($e->getCode(), 0);
            Assert::same($e->getPrevious(), $cause);

            return;
        }

        Assert::fail('a StoreException should have been thrown');
    }
}
