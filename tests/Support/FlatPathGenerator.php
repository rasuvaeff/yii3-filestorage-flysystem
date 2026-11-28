<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Flat keys, `<group>/ab/cd/<random>.jpg`, the shape of a content-addressed
 * fan-out — which only `FlatSiblingLayout` can hold.
 *
 * @internal
 */
final readonly class FlatPathGenerator implements PathGeneratorInterface
{
    #[Override]
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string
    {
        return $groupName . '/ab/cd/' . bin2hex(random_bytes(16)) . '.jpg';
    }
}
