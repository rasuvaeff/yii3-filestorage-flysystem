<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use DateTimeInterface;
use League\Flysystem\Config;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use Override;
use RuntimeException;

/**
 * Stands in for an adapter that can mint URLs — S3 in production.
 *
 * Injected into a real `Filesystem` rather than replacing it, so the store is
 * exercised against genuine Flysystem plumbing: the config array really is
 * passed through, and the exceptions really are Flysystem's own.
 *
 * @internal
 */
final class StubUrlGenerator implements PublicUrlGenerator, TemporaryUrlGenerator
{
    /** @var array<string, mixed> Whatever the store last passed through. */
    public array $lastConfig = [];

    public function __construct(
        private readonly bool $throwing = false,
        private readonly bool $empty = false,
        private readonly bool $failing = false,
    ) {}

    #[Override]
    public function publicUrl(string $path, Config $config): string
    {
        if ($this->throwing) {
            throw UnableToGeneratePublicUrl::noGeneratorConfigured($path);
        }
        if ($this->failing) {
            throw UnableToReadFile::fromLocation($path, 'the signing service is down');
        }

        return $this->empty ? '' : "https://cdn.example.com/{$path}";
    }

    #[Override]
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        if ($this->throwing) {
            throw UnableToGenerateTemporaryUrl::dueToError($path, new RuntimeException('no'));
        }
        if ($this->failing) {
            throw UnableToReadFile::fromLocation($path, 'the signing service is down');
        }

        /** @var array<string, mixed> $options */
        $options = $config->get('get_object_options', []);
        $this->lastConfig = ['get_object_options' => $options];

        return $this->empty ? '' : "https://cdn.example.com/{$path}?signed";
    }
}
