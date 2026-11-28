<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Url;

use Rasuvaeff\Yii3FilestorageFlysystem\Url\WellFormedMediaType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(WellFormedMediaType::class)]
final class WellFormedMediaTypeTest
{
    #[DataProvider('wellFormedProvider')]
    public function aWellFormedTypeIsKeptTrimmed(string $mediaType, string $expected): void
    {
        Assert::same(WellFormedMediaType::of($mediaType), $expected);
    }

    public static function wellFormedProvider(): iterable
    {
        yield 'plain' => ['image/png', 'image/png'];
        yield 'surrounding spaces and tabs' => [" \timage/png\t ", 'image/png'];
        yield 'token parameter' => ['text/plain; charset=utf-8', 'text/plain; charset=utf-8'];
        yield 'no space before the parameter' => ['text/plain;charset=utf-8', 'text/plain;charset=utf-8'];
        yield 'quoted parameter with an escape' => ['text/plain; x="a\\"b c"', 'text/plain; x="a\\"b c"'];
        yield 'every tchar' => ["a!#$%&'*+.^_`|~-1/b", "a!#$%&'*+.^_`|~-1/b"];
    }

    #[DataProvider('malformedProvider')]
    public function aMalformedTypeIsNull(?string $mediaType): void
    {
        Assert::null(WellFormedMediaType::of($mediaType));
    }

    public static function malformedProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'blank' => [" \t"];
        yield 'CR/LF' => ["image/svg+xml\r\nX: y"];
        yield 'trailing LF' => ["image/png\n"];
        yield 'leading LF' => ["\nimage/png"];
        yield 'no subtype' => ['image/'];
        yield 'no type' => ['/png'];
        yield 'separator' => ['image/p@ng'];
        yield 'trailing semicolon' => ['text/plain;'];
        yield 'empty value' => ['text/plain; a='];
        yield 'raw quote inside quotes' => ['text/plain; a="x"y"'];
        yield 'non-ASCII in quotes' => ["text/plain; a=\"\xC3\xA9\""];
    }
}
