<?php

use Aldeebhasan\Panorama\Dataset\Params;

it('reads values as properties, the way a fetch reads', function (): void {
    $params = Params::make(['from' => '2026-01-01', 'to' => '2026-09-30']);

    expect($params->from)->toBe('2026-01-01')
        ->and($params->to)->toBe('2026-09-30')
        ->and($params->missing)->toBeNull()
        ->and(isset($params->from))->toBeTrue()
        ->and(isset($params->missing))->toBeFalse();
});

it('reads values with a default', function (): void {
    $params = Params::make(['country' => 'DE']);

    expect($params->get('country'))->toBe('DE')
        ->and($params->get('city', 'all'))->toBe('all')
        ->and($params->has('country'))->toBeTrue()
        ->and($params->has('city'))->toBeFalse();
});

it('reads typed values without spreading mixed', function (): void {
    $params = Params::make(['page' => '3', 'name' => 42, 'live' => true, 'junk' => ['a']]);

    expect($params->int('page'))->toBe(3)
        ->and($params->string('name'))->toBe('42')
        ->and($params->bool('live'))->toBeTrue()
        ->and($params->int('junk', 1))->toBe(1)
        ->and($params->string('junk', 'x'))->toBe('x')
        ->and($params->bool('junk', false))->toBeFalse()
        ->and($params->int('absent'))->toBeNull();
});

it('tells filled apart from merely present', function (): void {
    $params = Params::make(['a' => 'x', 'b' => '', 'c' => null]);

    expect($params->filled('a'))->toBeTrue()
        ->and($params->filled('b'))->toBeFalse()
        ->and($params->filled('c'))->toBeFalse()
        ->and($params->has('c'))->toBeTrue();
});

it('narrows to the declared keys', function (): void {
    $params = Params::make(['from' => '1', 'to' => '2', 'secret' => '3']);

    expect($params->only(['from', 'to'])->all())->toBe(['from' => '1', 'to' => '2']);
});

it('merges without touching the original', function (): void {
    $params = Params::make(['a' => 1]);
    $merged = $params->merge(['b' => 2]);

    expect($params->all())->toBe(['a' => 1])
        ->and($merged->all())->toBe(['a' => 1, 'b' => 2]);
});

it('knows when it is empty', function (): void {
    expect(Params::make()->isEmpty())->toBeTrue()
        ->and(Params::make(['a' => 1])->isEmpty())->toBeFalse();
});

it('fingerprints the same values the same way whatever their order', function (): void {
    expect(Params::make(['a' => 1, 'b' => 2])->fingerprint())
        ->toBe(Params::make(['b' => 2, 'a' => 1])->fingerprint())
        ->and(Params::make(['a' => 1])->fingerprint())
        ->not->toBe(Params::make(['a' => 2])->fingerprint());
});

it('casts non string keys when built from a loose array', function (): void {
    expect(Params::make([0 => 'first', 'name' => 'Ada'])->all())
        ->toBe(['0' => 'first', 'name' => 'Ada']);
});
