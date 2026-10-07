<?php

use Aldeebhasan\Panorama\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/*
 * Every workspace engine must pass the same suite. Adding an engine means
 * adding it here, and nothing else.
 */
dataset('engines', [
    'database' => ['database'],
]);

/**
 * Run an Artisan command and return its exit code and output together.
 *
 * The `artisan()` helper matches each output expectation against a single
 * write, and a rendered table is one write, so several expectations can never
 * share it. Asserting on the captured output avoids that trap.
 *
 * @param array<string,mixed> $arguments
 * @return array{int,string}
 */
function console(string $command, array $arguments = []): array
{
    $status = Illuminate\Support\Facades\Artisan::call($command, $arguments);

    return [$status, Illuminate\Support\Facades\Artisan::output()];
}
