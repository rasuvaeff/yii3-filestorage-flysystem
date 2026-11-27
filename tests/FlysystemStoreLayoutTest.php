<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use InvalidArgumentException;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * What `delete()` removes under each layout, and what the inventory reports
 * about each object.
 */
#[Test]
#[Covers(FlysystemStore::class)]
final class FlysystemStoreLayoutTest
{
    private Filesystem $filesystem;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->filesystem = Fixtures::filesystem();
    }

    public function theDefaultLayoutIsTheDirectoryLayout(): void
    {
        Assert::instanceOf($this->store()->layout(), DirectoryLayout::class);
    }

    public function aConfiguredLayoutIsTheOneReported(): void
    {
        $layout = new FlatSiblingLayout();

        Assert::same($this->store(flat: true, layout: $layout)->layout(), $layout);
    }

    /**
     * An object at the store root is its own "directory". deleteDirectory()
     * on its key removes the prefix `key/`, not the object — which left a
     * deleted root-level file in place before the layout decided.
     */
    public function deletingARootLevelObjectRemovesTheObject(): void
    {
        $this->filesystem->write('loose.txt', 'a');
        $this->filesystem->write('loose.txt.bak', 'b');

        $this->store()->delete(Fixtures::file(relativePath: 'loose.txt'));

        Assert::false($this->filesystem->fileExists('loose.txt'));
        Assert::true($this->filesystem->fileExists('loose.txt.bak'));
    }

    /**
     * The flat layout owns `<base>.<ext>` and `<base>-*` beside it — not a
     * longer base that merely starts the same, not another base with an
     * underscore, not anything in a subdirectory of the same name.
     */
    public function theFlatLayoutDeletesTheObjectAndItsSiblingsOnly(): void
    {
        foreach (['abc.jpg', 'abc-s-v1.webp', 'abc-m.webp', 'abcd.jpg', 'abc_x.jpg', 'abc/nested.jpg'] as $name) {
            $this->filesystem->write('blobs/ab/cd/' . $name, $name);
        }
        $this->filesystem->write('blobs/ab/ce/abc-s-v1.webp', 'another directory');

        $this->store(flat: true)->delete(Fixtures::file(relativePath: 'blobs/ab/cd/abc.jpg'));

        Assert::same($this->paths(), [
            'blobs/ab/cd/abc/nested.jpg',
            'blobs/ab/cd/abc_x.jpg',
            'blobs/ab/cd/abcd.jpg',
            'blobs/ab/ce/abc-s-v1.webp',
        ]);
    }

    public function theFlatLayoutDeletesSiblingsAtTheStoreRoot(): void
    {
        foreach (['abc.jpg', 'abc-s.webp', 'abcd.jpg'] as $name) {
            $this->filesystem->write($name, $name);
        }

        $this->store(flat: true)->delete(Fixtures::file(relativePath: 'abc.jpg'));

        Assert::same($this->paths(), ['abcd.jpg']);
    }

    /**
     * `foo-bar.jpg` under the flat layout would read as a derivative of
     * `foo`; deleting it must refuse rather than take `foo.jpg` along.
     */
    public function aNameThatDoesNotFitTheFlatLayoutIsRefusedBeforeAnyDelete(): void
    {
        $this->filesystem->write('g/foo.jpg', 'a');
        $this->filesystem->write('g/foo-bar.jpg', 'b');

        try {
            $this->store(flat: true)->delete(Fixtures::file(relativePath: 'g/foo-bar.jpg'));
            Assert::fail('the flat layout should have refused the name');
        } catch (InvalidArgumentException) {
        }

        Assert::same($this->paths(), ['g/foo-bar.jpg', 'g/foo.jpg']);
    }

    /**
     * The timestamp and size come with the listing — no request per object —
     * and the timestamp is what keeps the orphan sweep off an upload in flight.
     */
    public function theInventoryCarriesTheListingsTimestampAndSize(): void
    {
        $this->filesystem->write('g/a/original.txt', 'abc', ['timestamp' => 1_700_000_000]);

        $objects = iterator_to_array($this->store()->objects(), preserve_keys: false);

        Assert::same(\count($objects), 1);
        Assert::instanceOf($objects[0], StoredObject::class);
        Assert::same($objects[0]->id->relativePath, 'g/a/original.txt');
        Assert::same($objects[0]->lastModified?->getTimestamp(), 1_700_000_000);
        Assert::same($objects[0]->size, 3);
    }

    /**
     * An adapter that lists without a timestamp yields an undated object,
     * which the sweep never deletes — not one dated "now", and not one dated
     * 1970, which would make every such object an immediate candidate.
     */
    public function anUndatedListingEntryStaysUndated(): void
    {
        $filesystem = Understudy::strict(Understudy::for(FilesystemOperator::class));
        when(fn() => $filesystem->listContents(Arg::any(), Arg::any()))
            ->answers(static fn(): DirectoryListing => new DirectoryListing([
                new FileAttributes('g/a/original.txt'),
                new FileAttributes('g/b/original.txt', 0, null, 0),
            ]));
        $store = new FlysystemStore(
            'flysystem',
            $filesystem,
            Fixtures::factory(),
            semantics: AdapterSemantics::guaranteed(orderedListing: true),
        );

        $objects = iterator_to_array($store->objects(), preserve_keys: false);

        Assert::same(\count($objects), 2);
        Assert::null($objects[0]->lastModified);
        Assert::null($objects[0]->size);
        Assert::same($objects[1]->lastModified?->getTimestamp(), 0);
        Assert::same($objects[1]->size, 0);
    }

    /**
     * The quarantine must not appear as inventory: the orphan sweep would
     * sweep it, `stat` and `verify` would count it. A sibling prefix that
     * merely starts the same is ordinary data.
     */
    public function theInventoryNeverListsTheQuarantine(): void
    {
        $this->filesystem->write('.quarantine/1700000000-0123456789abcdef/g/a/original.txt', 'held');
        $this->filesystem->write('.quarantine', 'a file named like the prefix');
        $this->filesystem->write('.quarantined/g/b/original.txt', 'live');
        $this->filesystem->write('g/c/original.txt', 'live');

        foreach ([false, true] as $ordered) {
            $store = new FlysystemStore(
                'flysystem',
                $this->filesystem,
                Fixtures::factory(),
                semantics: $ordered ? AdapterSemantics::guaranteed(orderedListing: true) : null,
                quarantinePrefix: '.quarantine',
            );

            $paths = array_map(
                static fn(StoredObject $o): string => $o->id->relativePath,
                iterator_to_array($store->objects(), preserve_keys: false),
            );
            sort($paths);

            Assert::same($paths, ['.quarantined/g/b/original.txt', 'g/c/original.txt']);
        }
    }

    public function withoutAQuarantineNothingIsHidden(): void
    {
        $this->filesystem->write('.quarantine/x/original.txt', 'data');

        Assert::same(\count(iterator_to_array($this->store()->objects(), preserve_keys: false)), 1);
        Assert::false($this->store()->isQuarantined('.quarantine/x/original.txt'));
    }

    private function store(bool $flat = false, ?FlatSiblingLayout $layout = null): FlysystemStore
    {
        return new FlysystemStore(
            'flysystem',
            $this->filesystem,
            Fixtures::factory(),
            layout: $flat ? ($layout ?? new FlatSiblingLayout()) : null,
        );
    }

    /**
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
}
