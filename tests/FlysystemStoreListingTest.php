<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Listing\Continuation;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\CountingAdapter;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * What one paged pass over the inventory and the quarantine costs, and that
 * a wrongly declared listing order is an error rather than a short inventory
 * (review 1.0 #6, #7, #8).
 */
#[Test]
#[Covers(FlysystemStore::class)]
#[Covers(Continuation::class)]
final class FlysystemStoreListingTest
{
    private const int OBJECTS = 1200;
    private const int PAGE = 100;
    private const string PREFIX = '.quarantine';

    /**
     * Was 42.5 entries per object in the review's probe: a full listing plus
     * a sort on every page.
     */
    public function aWholePassListsAnUndeclaredStoreOnce(): void
    {
        [$adapter, $store, $paths] = $this->populated(sorted: false, orderedListing: false);

        Assert::same($this->pass($store), $paths);
        Assert::same($adapter->calls, 1);
        Assert::same($adapter->files, self::OBJECTS);
    }

    /**
     * Was 23.3 entries per object: the declared order skipped the sort but
     * still restarted the listing on every page.
     */
    public function aWholePassStreamsADeclaredOrderedListingOnce(): void
    {
        [$adapter, $store, $paths] = $this->populated(sorted: true, orderedListing: true);

        Assert::same($this->pass($store), $paths);
        Assert::same($adapter->calls, 1);
        Assert::same($adapter->files, self::OBJECTS);
    }

    /**
     * The probe lost 9014 of 10000 objects silently. Every consecutive pair
     * is compared, so an adapter that does not list in `strcmp` order is
     * caught before a single object is skipped.
     */
    public function aWronglyDeclaredOrderIsAnErrorNotAShortInventory(): void
    {
        [, $store] = $this->populated(sorted: false, orderedListing: true);

        Expect::exception(StoreException::class)->withMessageContaining('does not list in strcmp order');

        $this->pass($store);
    }

    public function aWronglyDeclaredOrderIsCaughtEvenBeforeTheCursor(): void
    {
        $adapter = new CountingAdapter(sorted: false);
        $filesystem = new Filesystem($adapter);
        $filesystem->write('g/b/original.txt', 'b');
        $filesystem->write('g/a/original.txt', 'a');
        $store = new FlysystemStore('flysystem', $filesystem, Fixtures::factory(), orderedListing: true);

        Expect::exception(StoreException::class)->withMessageContaining('"g/a/original.txt" after "g/b/original.txt"');

        iterator_to_array($store->objects('g/c/original.txt'), preserve_keys: false);
    }

    /**
     * A resumed run has a new store and only the cursor: a fresh listing,
     * skipped forward — slower than a continuation, never wrong.
     */
    public function aFreshStoreResumesAfterACursor(): void
    {
        foreach ([false, true] as $ordered) {
            [$adapter, $store, $paths] = $this->populated(sorted: $ordered, orderedListing: $ordered);
            $cursor = $paths[499];
            $fresh = new FlysystemStore('flysystem', new Filesystem($adapter), Fixtures::factory(), orderedListing: $ordered);

            Assert::same($this->paths($fresh->objects($cursor, 10)), \array_slice($paths, 500, 10));
            Assert::same($this->paths($store->objects($cursor, 10)), \array_slice($paths, 500, 10));
        }
    }

    /**
     * `gc` stops in the middle of a page when its limit is reached and
     * resumes from the last object it handled, not from the last one listed.
     */
    public function aPageReadOnlyInPartResumesWhereTheReaderStopped(): void
    {
        [$adapter, $store, $paths] = $this->populated(sorted: false, orderedListing: false);

        $read = [];
        foreach ($store->objects(null, self::PAGE) as $object) {
            $read[] = $object->id->relativePath;
            if (\count($read) === 3) {
                break;
            }
        }

        Assert::same($this->paths($store->objects($read[2], 2)), \array_slice($paths, 3, 2));
        Assert::same($adapter->calls, 1);
    }

    public function aCursorElsewhereStartsANewListing(): void
    {
        [$adapter, $store, $paths] = $this->populated(sorted: false, orderedListing: false);

        $this->paths($store->objects(null, 10));
        Assert::same($this->paths($store->objects($paths[4], 2)), \array_slice($paths, 5, 2));
        Assert::same($adapter->calls, 2);

        Assert::same($this->paths($store->objects(null, 1)), [$paths[0]]);
        Assert::same($adapter->calls, 3);
    }

    /**
     * An exhausted pass leaves nothing parked: the next pass from the same
     * cursor lists again rather than answering "empty" forever.
     */
    public function anExhaustedPassIsNotContinued(): void
    {
        [$adapter, $store, $paths] = $this->populated(sorted: false, orderedListing: false);
        $last = $paths[self::OBJECTS - 1];

        $this->pass($store);
        Assert::same($this->paths($store->objects($last)), []);
        Assert::same($adapter->calls, 2);
    }

    /**
     * No generator emits a dot-segment, and `.keep`, `.DS_Store` or
     * `.well-known/` in a shared bucket belong to someone else (core review
     * 1.0 B2).
     */
    public function hiddenEntriesAreNeverListed(): void
    {
        foreach ([false, true] as $ordered) {
            $filesystem = new Filesystem(new CountingAdapter(sorted: true));
            foreach (['.htaccess', '.well-known/x', 'g/.keep', 'g/a/.DS_Store', 'g/a/original.txt'] as $path) {
                $filesystem->write($path, 'x');
            }
            $store = new FlysystemStore('flysystem', $filesystem, Fixtures::factory(), orderedListing: $ordered);

            Assert::same($this->paths($store->objects()), ['g/a/original.txt']);
        }
    }

    /**
     * One shallow listing of the prefix per pass, one deep listing per entry
     * — not every token listed again on every page.
     */
    public function aWholeQuarantinePassListsThePrefixOnce(): void
    {
        foreach ([false, true] as $ordered) {
            $adapter = new CountingAdapter(sorted: $ordered);
            $filesystem = new Filesystem($adapter);
            $tokens = $this->quarantineEntries($filesystem, 120);
            $store = new FlysystemStore(
                'flysystem',
                $filesystem,
                Fixtures::factory(),
                quarantinePrefix: self::PREFIX,
                orderedListing: $ordered,
            );
            $adapter->reset();

            $seen = [];
            $after = null;
            while (($page = iterator_to_array($store->quarantined($after, 25), preserve_keys: false)) !== []) {
                foreach ($page as $entry) {
                    $seen[] = $entry->token;
                    $after = $entry->token;
                }
            }

            Assert::same($seen, $tokens);
            Assert::same($adapter->calls, 1 + \count($tokens));
        }
    }

    public function aWronglyDeclaredOrderOfTheQuarantineIsAnError(): void
    {
        $filesystem = new Filesystem(new CountingAdapter(sorted: false));
        $tokens = $this->quarantineEntries($filesystem, 5);
        $filesystem->write(self::PREFIX . '/' . $tokens[0] . '/g/z/original.txt', 'x');
        $store = new FlysystemStore(
            'flysystem',
            $filesystem,
            Fixtures::factory(),
            quarantinePrefix: self::PREFIX,
            orderedListing: true,
        );

        Expect::exception(StoreException::class)->withMessageContaining('does not list in strcmp order');

        iterator_to_array($store->quarantined(), preserve_keys: false);
    }

    /**
     * @return array{CountingAdapter, FlysystemStore, list<string>}
     */
    private function populated(bool $sorted, bool $orderedListing): array
    {
        $adapter = new CountingAdapter(sorted: $sorted);
        $filesystem = new Filesystem($adapter);
        $paths = [];
        for ($i = 0; $i < self::OBJECTS; $i++) {
            $path = \sprintf('g/%02x/%s/original.jpg', $i % 16, md5((string) $i));
            $filesystem->write($path, 'x');
            $paths[] = $path;
        }
        sort($paths, SORT_STRING);
        $adapter->reset();

        return [
            $adapter,
            new FlysystemStore('flysystem', $filesystem, Fixtures::factory(), orderedListing: $orderedListing),
            $paths,
        ];
    }

    /**
     * Pages through the whole inventory the way `gc` does.
     *
     * @return list<string>
     */
    private function pass(FlysystemStore $store): array
    {
        $paths = [];
        $after = null;
        while (($page = $this->paths($store->objects($after, self::PAGE))) !== []) {
            array_push($paths, ...$page);
            $after = $page[\count($page) - 1];
        }

        return $paths;
    }

    /**
     * @param iterable<int, StoredObject> $objects
     *
     * @return list<string>
     */
    private function paths(iterable $objects): array
    {
        $paths = [];
        foreach ($objects as $object) {
            $paths[] = $object->id->relativePath;
        }

        return $paths;
    }

    /**
     * Entries written in reverse token order, so insertion order is not
     * `strcmp` order.
     *
     * @return list<string> The tokens, sorted.
     */
    private function quarantineEntries(Filesystem $filesystem, int $count): array
    {
        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            $tokens[] = QuarantinedObject::newToken(new DateTimeImmutable('@' . (1_700_000_000 + $i)));
        }
        sort($tokens, SORT_STRING);

        foreach (array_reverse($tokens) as $token) {
            $filesystem->write(self::PREFIX . '/' . $token . '/g/a/original.txt', 'x');
        }

        return $tokens;
    }
}
