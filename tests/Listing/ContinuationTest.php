<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Listing;

use ArrayIterator;
use Rasuvaeff\Yii3FilestorageFlysystem\Listing\Continuation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Review 1.0 pass 2, N6: a listing parked by a pass stopped at `--limit` or
 * `--max-scan` must not be continued hours later — it is a snapshot of the
 * bucket as it was, and objects added since in the rest of its range would
 * be missed by that pass.
 */
#[Test]
#[Covers(Continuation::class)]
final class ContinuationTest
{
    private int $now = 0;

    public function aListingResumedInTimeContinues(): void
    {
        $continuation = $this->continuation();
        $continuation->park(new ArrayIterator(['a', 'b']), 'a');
        $this->now += 300 * 1_000_000_000;

        $resumed = $continuation->resume('a');

        Assert::notNull($resumed);
        Assert::same($resumed?->current(), 'b');
    }

    public function aListingParkedTooLongIsNotResumed(): void
    {
        $continuation = $this->continuation();
        $iterator = new ArrayIterator(['a', 'b']);
        $continuation->park($iterator, 'a');
        $this->now += 300 * 1_000_000_000 + 1;

        Assert::null($continuation->resume('a'));
        // Released, not only skipped: the store no longer holds it.
        Assert::false($continuation->holds($iterator));
    }

    /** The default is an hour: a slow page of gc work must not drop the pass's listing. */
    public function theDefaultIdleLimitIsAnHour(): void
    {
        $now = 0;
        /** @var Continuation<string> $continuation */
        $continuation = new Continuation(clock: static function () use (&$now): int {
            return $now;
        });
        $continuation->park(new ArrayIterator(['a', 'b']), 'a');
        $now = 3600 * 1_000_000_000;
        Assert::notNull($continuation->resume('a'));

        $continuation->park(new ArrayIterator(['a', 'b']), 'a');
        $now += 3600 * 1_000_000_000 + 1;
        Assert::null($continuation->resume('a'));
    }

    public function anotherCursorStartsOver(): void
    {
        $continuation = $this->continuation();
        $continuation->park(new ArrayIterator(['a', 'b']), 'a');

        Assert::null($continuation->resume('b'));
        Assert::null($continuation->resume('a'));
    }

    /** A pass that starts from the beginning releases whatever was parked. */
    public function aFreshPassReleasesTheParkedListing(): void
    {
        $continuation = $this->continuation();
        $iterator = new ArrayIterator(['a', 'b']);
        $continuation->park($iterator, 'a');

        Assert::null($continuation->resume(null));
        Assert::false($continuation->holds($iterator));
    }

    /**
     * @return Continuation<string>
     */
    private function continuation(): Continuation
    {
        return new Continuation(maxIdleSeconds: 300, clock: fn(): int => $this->now);
    }
}
