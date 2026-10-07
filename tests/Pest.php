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
