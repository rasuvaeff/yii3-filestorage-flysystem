<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AdapterSemantics::class)]
final class AdapterSemanticsTest
{
    public function guaranteedAssertsBoth(): void
    {
        $semantics = AdapterSemantics::guaranteed();

        Assert::true($semantics->atomicVisibility);
        Assert::true($semantics->immutableContentKeys);
    }

    /**
     * There is no partial guarantee. A value object that accepted "atomic but
     * mutable" would make deduplication opt-out, and what it would be hiding is
     * silent corruption found months later.
     */
    #[DataProvider('incompleteProvider')]
    public function anythingLessThanBothIsRefused(bool $atomic, bool $immutable): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessageContaining('both atomic visibility and immutable content keys');

        new AdapterSemantics(atomicVisibility: $atomic, immutableContentKeys: $immutable);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function incompleteProvider(): iterable
    {
        yield 'neither' => [false, false];
        yield 'not atomic' => [false, true];
        yield 'keys not immutable' => [true, false];
    }

    /**
     * The message has to say what to do instead, because the reader is someone
     * who just found out their adapter cannot deduplicate.
     */
    public function theRefusalNamesTheWayForward(): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessageContaining('keep the plain FlysystemStore binding, which stays unique-only');

        new AdapterSemantics(atomicVisibility: false, immutableContentKeys: false);
    }
}
