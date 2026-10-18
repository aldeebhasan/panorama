<?php

namespace Aldeebhasan\Panorama\Query;

use Closure;
use Generator;
use IteratorAggregate;
use Traversable;

/**
 * The rows a merge produced, streamed.
 *
 * The rows are a generator, so a result of any size costs one row of memory
 * and can only be walked once. `columns()` is still available before anything
 * is consumed: the first row is looked at without being taken, so asking what
 * the columns are costs one row from the source and loses none.
 *
 * A result may also own the lifetime of whatever produced it, through
 * {@see whenDone()}: the callback fires once the rows run out, when the
 * generator is abandoned, or when the result is destroyed, so whatever the rows
 * are read from lives exactly as long as they are being read.
 *
 * Never wrap one result in another. Both would peek a row to learn their
 * columns, and peeking twice through a delegated generator silently drops a
 * row. Register a callback on the one result instead.
 *
 * @implements IteratorAggregate<int,array<string,mixed>>
 */
final class Result implements IteratorAggregate
{
    /** @var Generator<int,array<string,mixed>> */
    private Generator $rows;

    /** @var list<string>|null */
    private ?array $columns = null;

    /** @var array<string,mixed>|null */
    private ?array $peeked = null;

    private bool $primed = false;
    private bool $consumed = false;
    private int $yielded = 0;
    private ?Closure $onDone = null;
    private bool $done = false;

    /**
     * @param iterable<array-key,array<string,mixed>> $rows
     * @param list<string>|null $columns the declared columns, peeked from the first row when null
     */
    public function __construct(iterable $rows, ?array $columns = null)
    {
        // Static on purpose: a non static closure would bind $this, and the
        // generator holding the Result that holds the generator is a cycle
        // refcounting cannot free, so cleanup would wait for the GC.
        $this->rows = (static function () use ($rows): Generator {
            yield from $rows;
        })();

        $this->columns = $columns;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function of(array $rows): self
    {
        return new self($rows);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * The column names, in order.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $this->prime();

        return $this->columns = $this->peeked === null ? [] : array_keys($this->peeked);
    }

    /**
     * Run a callback once the rows are finished with, however that happens.
     */
    public function whenDone(Closure $callback): self
    {
        $this->onDone = $callback;

        return $this;
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function rows(): Generator
    {
        $this->consumed = true;

        try {
            $this->prime();

            // An explicit walk rather than `yield from`: the generator has
            // already been started by the peek, and delegating to a started
            // generator from here is where a row goes missing.
            while ($this->rows->valid()) {
                $this->yielded++;

                yield $this->rows->current();

                $this->rows->next();
            }
        } finally {
            $this->finish();
        }
    }

    public function getIterator(): Traversable
    {
        return $this->rows();
    }

    /**
     * Materialize every row. Only for results small enough to hold at once.
     *
     * @return list<array<string,mixed>>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->rows(), false);
    }

    /**
     * The first row, or null when there are none. Consumes it.
     *
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        foreach ($this->rows() as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Whether the rows have already been walked. A result is single use.
     */
    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    /**
     * How many rows have been handed out so far. Once the rows run out, the
     * size of the result, learned without holding it.
     */
    public function yielded(): int
    {
        return $this->yielded;
    }

    public function __destruct()
    {
        $this->finish();
    }

    /**
     * Fire the completion callback, exactly once.
     */
    private function finish(): void
    {
        if ($this->done) {
            return;
        }

        $this->done = true;

        // Release the source before the callback runs. A database cursor still
        // holding an open statement locks the very tables the cleanup is about
        // to drop, and dropping the last reference closes it.
        $this->rows = (static function (): Generator {
            yield from [];
        })();
        $this->peeked = null;

        if ($this->onDone !== null) {
            ($this->onDone)();
        }
    }

    /**
     * Start the source and look at the first row without consuming it.
     *
     * `valid()` runs the generator up to its first yield and `current()` reads
     * it, so the row stays where it is: the walk in {@see rows()} picks it up
     * as its first value.
     */
    private function prime(): void
    {
        if ($this->primed) {
            return;
        }

        $this->primed = true;

        if ($this->rows->valid()) {
            $this->peeked = $this->rows->current();
        }
    }
}
