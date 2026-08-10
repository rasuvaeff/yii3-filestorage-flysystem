<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Url;

use Override;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;

/**
 * Delivery options as S3 `GetObject` response overrides.
 *
 * `AwsS3V3Adapter::temporaryUrl()` reads `get_object_options` out of the
 * Flysystem config and merges it into the presigned `GetObject` command, so
 * `ResponseContentType` and `ResponseContentDisposition` end up signed into the
 * URL — S3 then returns them whatever the object's stored metadata says. That
 * is what makes a presigned URL able to honour a delivery policy at all.
 *
 * Works with any S3-compatible service that implements the response-override
 * parameters, which is all of the common ones.
 *
 * @api
 */
final readonly class S3TemporaryUrlOptions implements TemporaryUrlOptionsInterface
{
    #[Override]
    public function for(DeliveryOptions $options): array
    {
        // The name is already CR/LF-free — DeliveryOptions strips those — but it
        // still has to survive being quoted, and a bare `"` would end the
        // parameter early. RFC 6266 gives the ASCII fallback a quoted-string,
        // and `filename*` carries anything non-ASCII, percent-encoded.
        // Per character, not per byte: `/u` keeps "отчёт.pdf" a five-underscore
        // placeholder instead of a ten-underscore one. Invalid UTF-8 makes
        // preg_replace return null, which the fallback below covers.
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $options->downloadName) ?? 'file';
        $ascii = str_replace(['\\', '"'], '_', $ascii);

        $disposition = ($options->forceDownload ? 'attachment' : 'inline')
            . '; filename="' . $ascii . '"'
            . "; filename*=UTF-8''" . rawurlencode($options->downloadName);

        return [
            'get_object_options' => [
                'ResponseContentType' => $options->responseMediaType,
                'ResponseContentDisposition' => $disposition,
            ],
        ];
    }
}
