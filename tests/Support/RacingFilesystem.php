<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Closure;
use League\Flysystem\Filesystem;
use Override;

/**
 * A filesystem that lets another writer run between an existence check and
 * whatever the caller does with its answer.
 *
 * The answer is taken first and returned afterwards, so the caller acts on a
 * stale "absent" while the other writer has already published — the
 * interleaving two concurrent requests produce, made deterministic.
 *
 * @internal
 */
final class RacingFilesystem extends Filesystem
{
    private ?Closure $racer = null;

    /**
     * @param Closure(): void $racer Runs once, inside the next `fileExists()`.
     */
    public function raceOnNextExistenceCheck(Closure $racer): void
    {
        $this->racer = $racer;
    }

    #[Override]
    public function fileExists(string $location): bool
    {
        $answer = parent::fileExists($location);

        $racer = $this->racer;
        $this->racer = null;
        if ($racer instanceof Closure) {
            $racer();
        }

        return $answer;
    }
}
