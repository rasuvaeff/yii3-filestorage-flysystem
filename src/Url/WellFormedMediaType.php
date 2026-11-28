<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Url;

/**
 * RFC 9110 §8.3.1 `media-type` — `type "/" subtype *( OWS ";" OWS parameter )`,
 * parameter values a token or a quoted-string, ASCII only.
 *
 * A stored media type is validated by core only as non-empty, and both the
 * object's `ContentType` and a presigned `ResponseContentType` are echoed back
 * as a header. Stripping CR/LF from a type glued the tokens around them —
 * `image/svg+xml\r\nX: y` became `image/svg+xmlX: y`, in no active list and
 * parsed by no browser (review 1.0 pass 2, N4) — so a type is used whole or
 * not at all.
 *
 * @internal
 */
final readonly class WellFormedMediaType
{
    private const string TOKEN = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";

    private const string QUOTED = '"(?:[\t \x21\x23-\x5B\x5D-\x7E]|\\\\[\t \x21-\x7E])*"';

    /**
     * The type with surrounding spaces and tabs trimmed, or null when it is
     * not a media type.
     *
     * @return non-empty-string|null
     */
    public static function of(?string $mediaType): ?string
    {
        if ($mediaType === null) {
            return null;
        }

        $mediaType = trim($mediaType, " \t");
        $pattern = '{^' . self::TOKEN . '/' . self::TOKEN
            . '(?:[ \t]*;[ \t]*' . self::TOKEN . '=(?:' . self::TOKEN . '|' . self::QUOTED . '))*$}D';

        return $mediaType !== '' && preg_match($pattern, $mediaType) === 1 ? $mediaType : null;
    }
}
