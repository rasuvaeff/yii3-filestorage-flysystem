<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use League\Flysystem\FilesystemOperator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\FlatPathGenerator;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\TemporaryUrlOptionsInterface;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\BuildingException;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * `config/di.php` is covered by neither cs, nor psalm, nor the unit suite — it
 * is not in `src`. Without this test a mistake there surfaces at deploy time,
 * so it is exercised through a real container rather than by reading the array.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function theStoreResolvesOnceTheApplicationBindsAnOperator(): void
    {
        $store = $this->container()->get(StoreInterface::class);

        Assert::instanceOf($store, FlysystemStore::class);
        Assert::same($store->name(), 'flysystem');
    }

    public function theWiredStoreActuallyWrites(): void
    {
        $store = $this->container()->get(StoreInterface::class);

        $result = $store->write(Fixtures::upload('hello'), 'common', new RandomPathGenerator());

        Assert::same($result->size, 5);
    }

    /**
     * Unbound is the ordinary case — most adapters cannot presign at all — and
     * it has to resolve rather than explode. This is the
     * nullable-constructor-default shape that has bitten this monorepo before.
     */
    public function theOptionalUrlOptionsMapperResolvesAsAbsent(): void
    {
        Assert::instanceOf($this->container()->get(StoreInterface::class), FlysystemStore::class);
    }

    public function bindingTheMapperReachesTheStore(): void
    {
        $container = $this->container([
            TemporaryUrlOptionsInterface::class => static fn(): TemporaryUrlOptionsInterface
                => new S3TemporaryUrlOptions(),
        ]);

        Assert::instanceOf($container->get(StoreInterface::class), FlysystemStore::class);
    }

    /**
     * Shipped without a quarantine: a prefix is a name reserved in the
     * application's bucket, so the application chooses it.
     */
    public function theDefaultStoreHasNoQuarantineAndTheDefaultLayout(): void
    {
        $store = $this->container()->get(StoreInterface::class);

        Assert::instanceOf($store, FlysystemStore::class);
        Assert::null($store->quarantinePrefix());
        Assert::instanceOf($store->layout(), DirectoryLayout::class);
    }

    public function theQuarantinePrefixParameterReachesTheStore(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-filestorage-flysystem']['quarantinePrefix'] = '.quarantine';

        $store = $this->container(params: $params)->get(StoreInterface::class);

        Assert::instanceOf($store, FlysystemStore::class);
        Assert::same($store->quarantinePrefix(), '.quarantine');
        Assert::null($store->quarantineProblem());
    }

    public function bindingALayoutReachesTheStore(): void
    {
        $store = $this->container([
            ObjectLayoutInterface::class => FlatSiblingLayout::class,
        ])->get(StoreInterface::class);

        Assert::instanceOf($store->layout(), FlatSiblingLayout::class);
    }

    /**
     * The review's A1 at boot: a path generator that emits flat keys under
     * the default DirectoryLayout makes deleting one file delete its
     * neighbours. The pair is checked when the store is built, not at the
     * first deletion.
     */
    public function aPathGeneratorThatDoesNotFitTheLayoutIsRefusedAtBoot(): void
    {
        try {
            $this->container([
                PathGeneratorInterface::class => static fn(): PathGeneratorInterface => new FlatPathGenerator(),
            ])->get(StoreInterface::class);
            Assert::fail('a flat path generator under the directory layout should not boot');
        } catch (BuildingException $e) {
            $cause = $e->getPrevious();
            Assert::instanceOf($cause, InvalidConfigException::class);
            Assert::string($cause->getMessage())->contains('Store "flysystem"');
            Assert::string($cause->getMessage())->contains(FlatPathGenerator::class);
        }
    }

    public function theSameGeneratorUnderAFittingLayoutBoots(): void
    {
        $store = $this->container([
            PathGeneratorInterface::class => static fn(): PathGeneratorInterface => new FlatPathGenerator(),
            ObjectLayoutInterface::class => FlatSiblingLayout::class,
        ])->get(StoreInterface::class);

        Assert::instanceOf($store->layout(), FlatSiblingLayout::class);
    }

    public function theCoreDefaultGeneratorFitsTheDefaultLayout(): void
    {
        $store = $this->container([
            PathGeneratorInterface::class => RandomPathGenerator::class,
        ])->get(StoreInterface::class);

        Assert::instanceOf($store->layout(), DirectoryLayout::class);
    }

    public function listingOrderIsNotAssumedByDefault(): void
    {
        $store = $this->container()->get(StoreInterface::class);

        Assert::instanceOf($store, FlysystemStore::class);
        Assert::false($store->orderedListing());
    }

    public function theOrderedListingParameterReachesTheStore(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-filestorage-flysystem']['orderedListing'] = true;

        $store = $this->container(params: $params)->get(StoreInterface::class);

        Assert::instanceOf($store, FlysystemStore::class);
        Assert::true($store->orderedListing());
    }

    public function aBucketIsPrivateByDefault(): void
    {
        $store = $this->container()->get(StoreInterface::class);

        Assert::instanceOf($store, FlysystemStore::class);
        Assert::false($store->isPubliclyReadable());
    }

    public function thePublicBucketParameterReachesTheStore(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-filestorage-flysystem']['publicBucket'] = true;

        $store = $this->container(params: $params)->get(StoreInterface::class);

        Assert::instanceOf($store, FlysystemStore::class);
        Assert::true($store->isPubliclyReadable());
    }

    /**
     * The one-source rule. Core binds the facade, `-db` the metadata half, this
     * package the physical store. Either side claiming another's key makes
     * installing both a `yiisoft/config` `Duplicate key` error.
     */
    public function thisPackageBindsOnlyTheStore(): void
    {
        $definitions = $this->definitions();

        Assert::same(array_keys($definitions), [StoreInterface::class]);
        Assert::false(\array_key_exists(StorageInterface::class, $definitions));
        Assert::false(\array_key_exists(RepositoryInterface::class, $definitions));
    }

    /**
     * Bucket, credentials and adapter are application configuration. A package
     * that bound this would be guessing at a physical target.
     */
    public function theOperatorIsLeftToTheApplication(): void
    {
        Assert::false(\array_key_exists(FilesystemOperator::class, $this->definitions()));
    }

    /**
     * `params.php` has to carry every key `di.php` reads, or the package fails
     * to boot against its own defaults.
     */
    public function everyParameterTheWiringReadsIsShipped(): void
    {
        Assert::true(
            \array_key_exists('name', $this->params()['rasuvaeff/yii3-filestorage-flysystem']),
        );
        Assert::true(
            \array_key_exists('quarantinePrefix', $this->params()['rasuvaeff/yii3-filestorage-flysystem']),
        );
        Assert::same($this->params()['rasuvaeff/yii3-filestorage-flysystem']['orderedListing'] ?? null, expected: false);
        Assert::same($this->params()['rasuvaeff/yii3-filestorage-flysystem']['publicBucket'] ?? null, expected: false);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, array<string, mixed>>|null $params
     */
    private function container(array $extra = [], ?array $params = null): Container
    {
        $definitions = $this->definitions($params);

        $definitions[StreamFactoryInterface::class] = Psr17Factory::class;
        $definitions[FilesystemOperator::class] = static fn(): FilesystemOperator => Fixtures::filesystem();

        return new Container(ContainerConfig::create()->withDefinitions($extra + $definitions));
    }

    /**
     * @param array<string, array<string, mixed>>|null $params
     *
     * @return array<string, mixed>
     */
    private function definitions(?array $params = null): array
    {
        $params ??= $this->params();

        return require __DIR__ . '/../config/di.php';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function params(): array
    {
        return require __DIR__ . '/../config/params.php';
    }
}
