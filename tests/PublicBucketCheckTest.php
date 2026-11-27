<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use League\Flysystem\Filesystem;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemContentAddressableStore;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Core's `filestorage:check` applies its active-content rule to a store that
 * says it is publicly readable. A public bucket is such a store only when the
 * application declares it with `publicBucket: true` — answering `publicUrl()`
 * is not evidence, a private bucket builds the same URL and answers 403.
 */
#[Test]
#[Covers(FlysystemStore::class)]
#[Covers(FlysystemContentAddressableStore::class)]
final class PublicBucketCheckTest
{
    private const string ACTIVE_CONTENT = 'Group "*" accepts any media type, and store "bucket" serves every object it holds at a public URL';

    private Filesystem $filesystem;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->filesystem = Fixtures::filesystem();
    }

    public function aBucketDeclaredPublicFailsCheckWhenAGroupAcceptsActiveContent(): void
    {
        $tester = $this->check($this->store(publicBucket: true));

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains(self::ACTIVE_CONTENT);
    }

    public function aDeduplicatingStoreOverAPublicBucketIsPublicToo(): void
    {
        $store = new FlysystemContentAddressableStore(
            store: $this->store(publicBucket: true),
            semantics: AdapterSemantics::guaranteed(),
        );
        $tester = $this->check($store);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains(self::ACTIVE_CONTENT);
    }

    public function aPublicBucketWithInertAllowListsPassesAndIsListedAsPublic(): void
    {
        $tester = $this->check(
            $this->store(publicBucket: true),
            new PolicyRegistry(['*' => new UploadPolicy(allowedMimeTypes: ['image/png'])]),
            json: true,
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('"public"');
    }

    public function aBucketNotDeclaredPublicIsNotTreatedAsPublic(): void
    {
        $tester = $this->check($this->store(publicBucket: false), json: true);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::false(str_contains($tester->getDisplay(), '"public"'));
        Assert::false(str_contains($this->flatten($tester->getDisplay()), self::ACTIVE_CONTENT));
    }

    private function store(bool $publicBucket): FlysystemStore
    {
        return new FlysystemStore(
            'bucket',
            $this->filesystem,
            Fixtures::factory(),
            quarantinePrefix: '.quarantine',
            publicBucket: $publicBucket,
        );
    }

    private function check(StoreInterface $store, ?PolicyRegistry $policies = null, bool $json = false): CommandTester
    {
        $tester = new CommandTester(new CheckCommand(
            stores: new StoreRegistry([$store]),
            repository: new MemoryRepository(),
            policies: $policies ?? new PolicyRegistry(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
        ));
        $tester->execute($json ? ['--format' => 'json'] : []);

        return $tester;
    }

    private function flatten(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
