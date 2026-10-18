<?php

use Aldeebhasan\Panorama\Query\Result;

it('reads the columns from the first row without losing it', function (): void {
    $result = Result::of([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Linus']]);

    expect($result->columns())->toBe(['id', 'name'])
        ->and($result->toArray())->toHaveCount(2);
});

it('prefers declared columns over peeking', function (): void {
    $result = new Result([['id' => 1]], ['a', 'b']);

    expect($result->columns())->toBe(['a', 'b']);
});

it('reports no columns for an empty result', function (): void {
    expect(Result::of([])->columns())->toBe([])
        ->and(Result::empty()->columns())->toBe([])
        ->and(Result::empty()->toArray())->toBe([]);
});

it('yields every row in order after the columns were read', function (): void {
    $result = Result::of([['n' => 1], ['n' => 2], ['n' => 3]]);
    $result->columns();

    expect(array_column($result->toArray(), 'n'))->toBe([1, 2, 3]);
});

it('pulls one row at a time from its source', function (): void {
    $pulled = 0;
    $source = (function () use (&$pulled): Generator {
        foreach ([['n' => 1], ['n' => 2], ['n' => 3]] as $row) {
            $pulled++;

            yield $row;
        }
    })();

    $result = new Result($source);

    // Reading the columns costs exactly one row.
    $result->columns();
    expect($pulled)->toBe(1);

    foreach ($result->rows() as $row) {
        break;
    }

    expect($pulled)->toBe(1);
});

it('knows whether it has been walked', function (): void {
    $result = Result::of([['n' => 1]]);

    expect($result->isConsumed())->toBeFalse();

    $result->toArray();

    expect($result->isConsumed())->toBeTrue();
});

it('returns the first row and nothing else', function (): void {
    expect(Result::of([['n' => 1], ['n' => 2]])->first())->toBe(['n' => 1])
        ->and(Result::of([])->first())->toBeNull();
});

it('is iterable directly', function (): void {
    $seen = [];
    foreach (Result::of([['n' => 1], ['n' => 2]]) as $row) {
        $seen[] = $row['n'];
    }

    expect($seen)->toBe([1, 2]);
});

it('runs the completion callback once the rows run out', function (): void {
    $done = 0;
    $result = Result::of([['n' => 1]])->whenDone(function () use (&$done): void {
        $done++;
    });

    expect($done)->toBe(0);

    $result->toArray();

    expect($done)->toBe(1);
});

it('runs the completion callback when the rows are abandoned', function (): void {
    $done = 0;

    (function () use (&$done): void {
        $result = Result::of([['n' => 1], ['n' => 2]])->whenDone(function () use (&$done): void {
            $done++;
        });

        foreach ($result->rows() as $row) {
            break;
        }
    })();

    expect($done)->toBe(1);
});

it('runs the completion callback when it is destroyed unread', function (): void {
    $done = 0;

    (function () use (&$done): void {
        Result::of([['n' => 1]])->whenDone(function () use (&$done): void {
            $done++;
        });
    })();

    expect($done)->toBe(1);
});

it('runs the completion callback exactly once', function (): void {
    $done = 0;
    $result = Result::of([['n' => 1]])->whenDone(function () use (&$done): void {
        $done++;
    });

    $result->toArray();
    $result->toArray();
    unset($result);

    expect($done)->toBe(1);
});

it('counts the rows it has handed out', function (): void {
    $result = Result::of([['a' => 1], ['a' => 2], ['a' => 3]]);

    expect($result->yielded())->toBe(0);

    $result->columns();

    expect($result->yielded())->toBe(0);

    $result->toArray();

    expect($result->yielded())->toBe(3);
});
