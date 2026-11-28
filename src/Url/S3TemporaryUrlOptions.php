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
 * `DeliveryOptions` already carries the decision made with the list it was
 * built from; this repeats the check on the well-formed type with core's
 * configured list — the instance core binds from its `activeMediaTypes`
 * param — so the application's extras hold even for options built from the
 * default list, and the PDF opt-out (`allowInline`) is not re-forced.
 *
 * @api
 */
final readonly class S3TemporaryUrlOptions implements TemporaryUrlOptionsInterface
{
    /**
     * @param ActiveMediaTypes $activeTypes Core's configured list: autowire it
     *        (core binds `ActiveMediaTypes::class`) or pass the same instance.
     *        No default on purpose — core's default keeps PDF forced and has
     *        none of the application's extras, so falling back to it silently
     *        disagreed with the proxy.
     */
    public function __construct(private ActiveMediaTypes $activeTypes) {}

    #[Override]
    public function for(DeliveryOptions $options): array
    {
        // A stored media type is validated only as non-empty, and S3 echoes
        // this override back as a header. Stripping CR/LF glued the tokens
        // around them into a type no list knows and no browser parses
        // (review 1.0 pass 2, N4): anything that is not RFC 9110
        // `type/subtype *(; parameter)` is served as an opaque download.
        $wellFormed = WellFormedMediaType::of($options->responseMediaType);
        $mediaType = $wellFormed ?? DeliveryOptions::DEFAULT_MEDIA_TYPE;
        $attachment = $wellFormed === null
            || $options->forceDownload
            || $this->activeTypes->contains($mediaType);

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
