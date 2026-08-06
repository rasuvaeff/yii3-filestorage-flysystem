<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A generator that returns the same path twice, which the real ones never do.
 *
 * Collision handling is a contract — a generated path that already exists is an
 * error, never sharing — and it is unreachable through `RandomPathGenerator`,
 * whose whole job is not to collide.
 *
 * @internal
 */
final readonly class FixedPathGenerator implements PathGeneratorInterface
{
    public function __construct(private string $path) {}

    #[Override]
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string
    {
        return $this->path;
    }
}
