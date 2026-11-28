<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Listing;

use Iterator;

/**
 * The listing a paged pass is in the middle of, kept between two calls.
 *
 * `objects($after, $limit)` and `quarantined($after, $limit)` are cursor
 * pages, and a caller that asks for the next page passes the last key it was
 * given. Starting a new listing for every page made one pass over n keys cost
 * n²/limit entries pulled from the adapter (review 1.0 #6, #8). When the
 * cursor is exactly where the previous page stopped, the same listing simply
 * continues; any other cursor — a resumed run, a fresh store instance, a page
 * the caller stopped reading early and resumed from an earlier key — starts a
 * new listing, which is the correct fallback, only slower.
 *
 * Pages are expected one at a time, as `gc` reads them. A page that finds
 * its listing taken by another one starts a fresh listing from its own key
 * rather than share the iterator.
 *
 * A parked listing expires: one not resumed within `maxIdleSeconds` is
 * dropped on the next call (review 1.0 pass 2, N6). It is a live view of the
 * bucket as the pass first saw it — without `orderedListing` a sorted
 * snapshot held in memory — and a long-lived worker continuing it hours
 * later would miss everything written since in the rest of its range. A
 * pass that starts from the beginning (`$after === null`) releases it too.
 *
 * Mutable on purpose inside a readonly store: it is a cache of where the
 * adapter's iterator is, not state of the store.
 *
 * @internal
 *
 * @template T
 */
final class Continuation
{
    /** @var Iterator<int, T>|null */
    private ?Iterator $iterator = null;

    private ?string $last = null;

    private int $parkedAt = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param positive-int $maxIdleSeconds How long a parked listing may wait
     *        for its next page.
     * @param (\Closure(): int)|null $clock Monotonic nanoseconds; `hrtime()`
     *        by default.
     */
    public function __construct(
        private readonly int $maxIdleSeconds = 300,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(as_number: true);
    }

    /**
     * Takes the parked listing out, advanced past `$after`, or null when the
     * cursor is not where it stopped.
     *
     * @return Iterator<int, T>|null
     */
    public function resume(?string $after): ?Iterator
    {
        $iterator = $this->iterator;
        $continues = $iterator instanceof \Iterator
            && $after !== null
            && $after === $this->last
            && ($this->clock)() - $this->parkedAt <= $this->maxIdleSeconds * 1_000_000_000;
        $this->clear();

        if (!$continues) {
            return null;
        }

        $iterator->next();

        return $iterator;
    }

    /**
     * @param Iterator<int, T> $iterator Positioned at the item keyed `$last`.
     */
    public function park(Iterator $iterator, string $last): void
    {
        $this->iterator = $iterator;
        $this->last = $last;
        $this->parkedAt = ($this->clock)();
    }

    /**
     * Whether `$iterator` is still the parked one — false once another page
     * resumed or replaced it.
     *
     * @param Iterator<int, T> $iterator
     */
    public function holds(Iterator $iterator): bool
    {
        return $this->iterator === $iterator;
    }

    public function clear(): void
    {
        $this->iterator = null;
        $this->last = null;
    }
}
