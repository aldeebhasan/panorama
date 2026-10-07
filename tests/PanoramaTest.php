<?php

use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\Facades\Panorama;
use Aldeebhasan\Panorama\Reports\Report;
use Aldeebhasan\Panorama\Tests\Reports\SampleReport;

beforeEach(function (): void {
    SampleReport::$builds = 0;
});

it('registers the reports declared in the config', function (): void {
    expect(Panorama::has('sample'))->toBeTrue()
        ->and(Panorama::all())->toBe(['sample' => 'Sample Report']);
});

it('generates a report with its filters applied', function (): void {
    expect(Panorama::generate('sample', ['country' => 'DE']))
        ->toBe(['country' => 'DE', 'total' => 10]);
});

it('discards filters that are not allowed', function (): void {
    expect(Panorama::generate('sample', ['unknown' => 'x']))
        ->toBe(['country' => 'all', 'total' => 10]);
});

it('throws when the report is unknown', function (): void {
    Panorama::generate('missing');
})->throws(ReportingException::class, 'No report registered under the key [missing].');

it('throws when registering a class that is not a report', function (): void {
    Panorama::register(stdClass::class);
})->throws(ReportingException::class);

it('registers a report instance at runtime', function (): void {
    $report = new class extends Report
    {
        protected string $key = 'runtime';

        public function build(): array
        {
            return ['ok' => true];
        }
    };

    Panorama::register($report);

    expect(Panorama::generate('runtime'))->toBe(['ok' => true]);
});

it('derives the key and the name from the class basename', function (): void {
    $report = new SampleReport;

    expect($report->key())->toBe('sample')
        ->and($report->name())->toBe('Sample Report');
});

it('caches the payload per filter set when a ttl is configured', function (): void {
    config()->set('panorama.cache.ttl', 60);

    Panorama::generate('sample', ['country' => 'DE']);
    Panorama::generate('sample', ['country' => 'DE']);
    expect(SampleReport::$builds)->toBe(1);

    Panorama::generate('sample', ['country' => 'FR']);
    expect(SampleReport::$builds)->toBe(2);
});

it('rebuilds the report after the cache is forgotten', function (): void {
    config()->set('panorama.cache.ttl', 60);

    Panorama::generate('sample', ['country' => 'DE']);
    Panorama::forget('sample', ['country' => 'DE']);
    Panorama::generate('sample', ['country' => 'DE']);

    expect(SampleReport::$builds)->toBe(2);
});

it('lists the registered reports through the console', function (): void {
    $this->artisan('panorama:list')
        ->expectsOutputToContain('Sample Report')
        ->assertSuccessful();
});

it('generates a report through the console', function (): void {
    $this->artisan('panorama:generate', ['key' => 'sample', '--filter' => ['country=DE']])
        ->expectsOutputToContain('"country": "DE"')
        ->assertSuccessful();
});

it('fails through the console when the report is unknown', function (): void {
    $this->artisan('panorama:generate', ['key' => 'missing'])->assertFailed();
});
