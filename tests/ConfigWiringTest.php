<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use League\Flysystem\FilesystemOperator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\TemporaryUrlOptionsInterface;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
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
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function container(array $extra = []): Container
    {
        $definitions = $this->definitions();

        $definitions[StreamFactoryInterface::class] = Psr17Factory::class;
        $definitions[FilesystemOperator::class] = static fn(): FilesystemOperator => Fixtures::filesystem();

        return new Container(ContainerConfig::create()->withDefinitions($extra + $definitions));
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        $params = $this->params();

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
