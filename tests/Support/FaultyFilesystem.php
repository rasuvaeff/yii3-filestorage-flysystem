<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Closure;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use Override;

/**
 * A real in-memory filesystem with a hook in front of the calls the
 * quarantine makes, so a test can fail one of them — or change the world
 * just before it — at a chosen location.
 *
 * @internal
 */
final class FaultyFilesystem extends Filesystem
{
    /** @var array<string, Closure(string): void> */
    private array $faults = [];

    /** @var list<array{string, bool}> Every listing asked for: location as passed, and whether deep. */
    public array $listings = [];

    /**
     * @param 'deleteDirectory'|'fileExists'|'listContents'|'move' $method
     * @param Closure(string): void $fault Receives the (first) location; throws to fail the call.
     */
    public function on(string $method, Closure $fault): void
    {
        $this->faults[$method] = $fault;
    }

    #[Override]
    public function fileExists(string $location): bool
    {
        $this->fault(__FUNCTION__, $location);

        return parent::fileExists($location);
    }

    #[Override]
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $this->fault(__FUNCTION__, $location);
        $this->listings[] = [$location, $deep];

        return parent::listContents($location, $deep);
    }

    #[Override]
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->fault(__FUNCTION__, $source);

        parent::move($source, $destination, $config);
    }

    #[Override]
    public function deleteDirectory(string $location): void
    {
        $this->fault(__FUNCTION__, $location);

        parent::deleteDirectory($location);
    }

    private function fault(string $method, string $location): void
    {
        $fault = $this->faults[$method] ?? null;
        if ($fault !== null) {
            $fault($location);
        }
    }
}
