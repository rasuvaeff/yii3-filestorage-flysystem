<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Url;

use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;

/**
 * Turns delivery options into the Flysystem config a presigned URL needs.
 *
 * A store-native temporary URL bypasses the application entirely, so whatever
 * response headers the object store attaches *are* the response headers. If a
 * group's policy says a file must arrive as an attachment with a known media
 * type, and the presigned URL cannot carry that, the URL is not policy
 * compliant — and `StoreUrlProviderInterface` says to return null rather than
 * hand out one that serves an uploaded HTML file inline from your bucket.
 *
 * Whether an adapter can carry them is adapter-specific: S3 has
 * `ResponseContentDisposition`, most adapters have nothing of the kind, and
 * Flysystem's config array is passed through opaquely. So the mapping is
 * injected, and its absence means "no presigned URLs from this store" —
 * safe by default, and explicit either way.
 *
 * @api
 */
interface TemporaryUrlOptionsInterface
{
    /**
     * @return array<string, mixed>|null Flysystem config, or null when these
     *         options cannot be enforced and no URL should be issued.
     */
    public function for(DeliveryOptions $options): ?array;
}
