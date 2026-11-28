<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use DateTimeImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Review 1.0 pass 2, N17: "a quarantined object is private" was asserted only
 * by `aQuarantinedObjectIsPrivate` in the Integration suite, which skips
 * itself on SeaweedFS (no object ACLs) — CI never checked it. This drives the
 * real `AwsS3V3Adapter` against a recording S3 client: the copy into the
 * quarantine must carry `ACL: private`, whatever the source's ACL was. What a
 * real S3 does with that ACL stays the gateway's business.
 */
#[Test]
#[Covers(FlysystemStore::class)]
final class S3QuarantineAclTest
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $commands = [];

    public function theCopyIntoTheQuarantineIsPrivate(): void
    {
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => $this->handler(...),
        ]);
        $store = new FlysystemStore(
            'flysystem',
            new Filesystem(new AwsS3V3Adapter($client, 'bucket')),
            Fixtures::factory(),
            quarantinePrefix: '.quarantine',
        );

        $entry = $store->quarantine(ObjectSet::tree(new StoredObjectId('g/key')), new DateTimeImmutable());

        Assert::notNull($entry);
        $copies = array_values(array_filter(
            $this->commands,
            static fn(array $command): bool => $command[0] === 'CopyObject',
        ));
        Assert::same(\count($copies), 1);
        Assert::same($copies[0][1]['Key'] ?? null, ".quarantine/{$entry?->token}/g/key/a.txt");
        Assert::same($copies[0][1]['ACL'] ?? null, 'private');
    }

    private function handler(CommandInterface $command): PromiseInterface
    {
        $this->commands[] = [$command->getName(), $command->toArray()];
        $key = (string) ($command['Key'] ?? '');

        return match ($command->getName()) {
            'HeadObject' => $key === 'g/key/a.txt'
                ? Create::promiseFor(new Result(['ContentLength' => 1]))
                : Create::rejectionFor(new S3Exception('Not Found', $command, [
                    'code' => 'NotFound',
                    'response' => new Response(404),
                ])),
            'ListObjectsV2' => Create::promiseFor(new Result([
                'Contents' => [['Key' => 'g/key/a.txt', 'Size' => 1, 'LastModified' => '2026-11-28T00:00:00Z']],
            ])),
            // The source was public: a copy that kept its ACL would stay public.
            'GetObjectAcl' => Create::promiseFor(new Result([
                'Grants' => [['Grantee' => ['URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'], 'Permission' => 'READ']],
            ])),
            default => Create::promiseFor(new Result([])),
        };
    }
}
