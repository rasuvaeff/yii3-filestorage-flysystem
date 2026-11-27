<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Url;

use Override;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
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
 * Active content ({@see ActiveMediaTypes}: HTML, SVG, XML, script, PDF) is
 * always signed as `attachment`. An S3 response cannot carry
 * `X-Content-Type-Options: nosniff`, so a presigned URL serving an uploaded
 * SVG inline from a bucket on your domain is stored XSS (review 1.0 A5).
 * `DeliveryOptions` already forces the default list; this repeats the check
 * with the list you pass, so the extra types you give `-web` can be given
 * here too.
 *
 * @api
 */
final readonly class S3TemporaryUrlOptions implements TemporaryUrlOptionsInterface
{
    private ActiveMediaTypes $activeTypes;

    /**
     * @param ActiveMediaTypes|null $activeTypes Null is core's default list;
     *        `ActiveMediaTypes::withExtra([...])` adds to it.
     */
    public function __construct(?ActiveMediaTypes $activeTypes = null)
    {
        $this->activeTypes = $activeTypes ?? new ActiveMediaTypes();
    }

    #[Override]
    public function for(DeliveryOptions $options): array
    {
        // A stored media type is validated only as non-empty, and S3 echoes
        // this override back as a header: no CR, LF or NUL may reach it.
        $mediaType = str_replace(["\r", "\n", "\0"], '', $options->responseMediaType);
        $mediaType = $mediaType === '' ? DeliveryOptions::DEFAULT_MEDIA_TYPE : $mediaType;
        $attachment = $options->forceDownload || $this->activeTypes->contains($mediaType);

        // The name is already CR/LF-free — DeliveryOptions strips those — but it
        // still has to survive being quoted, and a bare `"` would end the
        // parameter early. RFC 6266 gives the ASCII fallback a quoted-string,
        // and `filename*` carries anything non-ASCII, percent-encoded.
        // Per character, not per byte: `/u` keeps "отчёт.pdf" a five-underscore
        // placeholder instead of a ten-underscore one. Invalid UTF-8 makes
        // preg_replace return null, which the fallback below covers.
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $options->downloadName) ?? 'file';
        $ascii = str_replace(['\\', '"'], '_', $ascii);

        $disposition = ($attachment ? 'attachment' : 'inline')
            . '; filename="' . $ascii . '"'
            . "; filename*=UTF-8''" . rawurlencode($options->downloadName);

        return [
            'get_object_options' => [
                'ResponseContentType' => $mediaType,
                'ResponseContentDisposition' => $disposition,
            ],
        ];
    }
}
