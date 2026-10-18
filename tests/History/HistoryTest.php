<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Deliver\DestinationContract;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\History\NullRecorder;
use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\History\RecorderContract;
use Aldeebhasan\Panorama\History\Recording;
use Aldeebhasan\Panorama\History\RunStatus;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Render\RenderedReport;
use Aldeebhasan\Panorama\Tests\Reports\FinanceOnly;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Turn history on and create its table, the way an application would after
 * publishing the migration.
 */
function keepHistory(): void
{
    config()->set('panorama.history.enabled', true);
    app()->forgetInstance(RecorderContract::class);

    (include __DIR__ . '/../../database/migrations/create_report_runs_table.php.stub')->up();
}

function lastRun(): RecordedRun
{
    return RecordedRun::query()->latestFirst()->firstOrFail();
}

/**
 * A recorder that remembers what it was told, for the recording's own tests.
 */
function memoryRecorder(): RecorderContract
{
    return new class implements RecorderContract
    {
        /** @var list<string> */
        public array $calls = [];

        public function started(Recording $recording): int
        {
            $this->calls[] = 'started';

            return 7;
        }

        public function finished(Recording $recording): void
        {
            $this->calls[] = 'finished:' . $recording->status()?->value;
        }
    };
}

/*
|--------------------------------------------------------------------------
| switched off
|--------------------------------------------------------------------------
*/

it('keeps no history unless enabled', function (): void {
    expect(app(RecorderContract::class))->toBeInstanceOf(NullRecorder::class);

    app(Panorama::class)->run('all-orders')->toCsv()->contents();

    expect(Schema::hasTable('report_runs'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| what a run records
|--------------------------------------------------------------------------
*/

it('records a run read directly', function (): void {
    keepHistory();

    $csv = app(Panorama::class)->run('all-orders')->toCsv()->contents();

    $run = lastRun();

    expect($run->report)->toBe('all-orders')
        ->and($run->name)->toBe('All Orders')
        ->and($run->params)->toBe([])
        ->and($run->format)->toBe('csv')
        ->and($run->filename)->toBe('all-orders.csv')
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->destination)->toBeNull()
        ->and($run->path)->toBeNull()
        ->and($run->rows)->toBe(3)
        ->and($run->bytes)->toBe(strlen($csv))
        ->and($run->error)->toBeNull()
        ->and($run->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($run->finished_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('records the parameters the run was given', function (): void {
    keepHistory();

    app(Datasets::class)->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
    ]);

    app(Panorama::class)
        ->run('revenue-by-customer', ['from' => '2026-01-01', 'to' => '2026-12-31'])
        ->toJson()
        ->contents();

    expect(lastRun()->params)->toBe(['from' => '2026-01-01', 'to' => '2026-12-31'])
        ->and(lastRun()->rows)->toBe(2)
        ->and(lastRun()->format)->toBe('json');
});

it('records the user the run was authorized for', function (): void {
    keepHistory();

    $user = new class
    {
        /** @var list<string> */
        public array $can = ['view-finance-reports'];

        public function getAuthIdentifier(): int
        {
            return 42;
        }
    };

    $report = new class extends FinanceOnly
    {
        public function authorize(mixed $user = null): bool
        {
            return true;
        }
    };

    app(Panorama::class)->run($report, [], $user)->toCsv()->contents();

    expect(lastRun()->user_id)->toBe('42');
});

it('records a run stored on a disk with the path it took', function (): void {
    keepHistory();
    Storage::fake('reports');

    $path = app(Panorama::class)->run('all-orders')->toCsv()->store('reports', 'exports');

    expect(lastRun()->status)->toBe(RunStatus::Succeeded)
        ->and(lastRun()->destination)->toBe('storage')
        ->and(lastRun()->path)->toBe($path)
        ->and(lastRun()->rows)->toBe(3);
});

it('settles a download once its stream ends, not when the response is built', function (): void {
    keepHistory();

    $response = app(Panorama::class)->run('all-orders')->toCsv()->download();

    expect(lastRun()->status)->toBe(RunStatus::Running)
        ->and(lastRun()->destination)->toBe('download')
        ->and(lastRun()->finished_at)->toBeNull();

    ob_start();
    $response->sendContent();
    ob_end_clean();

    expect(lastRun()->status)->toBe(RunStatus::Succeeded)
        ->and(lastRun()->rows)->toBe(3)
        ->and(lastRun()->finished_at)->not->toBeNull();
});

it('records one row per run', function (): void {
    keepHistory();

    app(Panorama::class)->run('all-orders')->toCsv()->contents();
    app(Panorama::class)->run('all-orders')->toJson()->contents();

    expect(RecordedRun::query()->count())->toBe(2)
        ->and(RecordedRun::query()->pluck('format')->all())->toEqualCanonicalizing(['csv', 'json']);
});

it('records nothing for rows read without rendering', function (): void {
    keepHistory();

    app(Panorama::class)->run('all-orders')->get();

    expect(RecordedRun::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| failures
|--------------------------------------------------------------------------
*/

it('records a run whose data failed the schema', function (): void {
    keepHistory();

    app(Datasets::class)->fake('orders', [
        ['id' => 1, 'customer_id' => 'c-1', 'amount' => 'lots', 'created_at' => '2026-01-15'],
    ]);

    expect(fn() => app(Panorama::class)->run('all-orders')->toCsv()->contents())
        ->toThrow(PanoramaException::class);

    expect(lastRun()->status)->toBe(RunStatus::Failed)
        ->and(lastRun()->error)->toContain('amount')
        ->and(lastRun()->finished_at)->not->toBeNull();
});

it('records a run a destination refused', function (): void {
    keepHistory();

    $refusing = new class implements DestinationContract
    {
        public function name(): string
        {
            return 'outbox';
        }

        public function deliver(RenderedReport $report): mixed
        {
            throw new RuntimeException('The outbox is full.');
        }
    };

    expect(fn() => app(Panorama::class)->run('all-orders')->toCsv()->to($refusing))
        ->toThrow(RuntimeException::class, 'The outbox is full.');

    expect(lastRun()->status)->toBe(RunStatus::Failed)
        ->and(lastRun()->destination)->toBe('outbox')
        ->and(lastRun()->error)->toBe('RuntimeException: The outbox is full.');
});

it('records a run abandoned half way', function (): void {
    keepHistory();

    $chunks = app(Panorama::class)->run('all-orders')->toCsv()->chunks();
    $chunks->current();
    unset($chunks);

    expect(lastRun()->status)->toBe(RunStatus::Failed)
        ->and(lastRun()->error)->toBe('The report was abandoned before it was fully produced.');
});

it('keeps the first outcome when a rendered report is delivered twice', function (): void {
    keepHistory();
    Storage::fake('reports');

    $csv = app(Panorama::class)->run('all-orders')->toCsv();
    $csv->store('reports');

    expect(fn() => $csv->store('reports'))->toThrow(RuntimeException::class, 'already been delivered');

    expect(RecordedRun::query()->count())->toBe(1)
        ->and(lastRun()->status)->toBe(RunStatus::Succeeded);
});

/*
|--------------------------------------------------------------------------
| the recording itself
|--------------------------------------------------------------------------
*/

it('tells the recorder once when the run starts and once when it settles', function (): void {
    $recorder = memoryRecorder();
    $recording = new Recording($recorder, 'r', 'R', [], 'csv', 'r.csv', null, fn(): int => 0);

    $recording->start();
    $recording->start();
    $recording->succeed();
    $recording->fail('too late');

    expect($recorder->calls)->toBe(['started', 'finished:succeeded'])
        ->and($recording->id())->toBe(7)
        ->and($recording->error())->toBeNull();
});

it('starts a run that fails before it started', function (): void {
    $recorder = memoryRecorder();
    $recording = new Recording($recorder, 'r', 'R', [], 'csv', 'r.csv', null, fn(): int => 0);

    $recording->fail(new LogicException('nope'));

    expect($recorder->calls)->toBe(['started', 'finished:failed'])
        ->and($recording->error())->toBe('LogicException: nope');
});

it('reduces the parameters to what json can hold', function (): void {
    $recording = new Recording(new NullRecorder, 'r', 'R', [
        'from' => new DateTimeImmutable('2026-01-01 10:00:00+00:00'),
        'status' => RunStatus::Failed,
        'ids' => [1, 2],
        'flag' => true,
        'nothing' => null,
        'object' => new stdClass,
    ], 'csv', 'r.csv', null, fn(): int => 0);

    expect($recording->params())->toBe([
        'from' => '2026-01-01T10:00:00+00:00',
        'status' => 'failed',
        'ids' => [1, 2],
        'flag' => true,
        'nothing' => null,
        'object' => 'stdClass',
    ]);
});

/*
|--------------------------------------------------------------------------
| commands
|--------------------------------------------------------------------------
*/

it('lists the recent runs', function (): void {
    keepHistory();

    app(Panorama::class)->run('all-orders')->toCsv()->contents();

    [$status, $output] = console('reports:history');

    expect($status)->toBe(0)
        ->and($output)->toContain('all-orders')
        ->and($output)->toContain('succeeded')
        ->and($output)->toContain('csv');
});

it('lists only the failed runs of one report', function (): void {
    keepHistory();

    app(Panorama::class)->run('all-orders')->toCsv()->contents();
    $chunks = app(Panorama::class)->run('all-orders')->toJson()->chunks();
    $chunks->current();
    unset($chunks);

    [, $output] = console('reports:history', ['report' => 'all-orders', '--failed' => true]);

    expect($output)->toContain('json')
        ->and($output)->toContain('abandoned')
        ->and($output)->not->toContain('csv');

    [, $output] = console('reports:history', ['report' => 'finance-only']);

    expect($output)->toContain('No runs recorded yet.');
});

it('says so when history is disabled', function (): void {
    [$status, $output] = console('reports:history');

    expect($status)->toBe(0)->and($output)->toContain('History is disabled');

    [, $output] = console('reports:prune');

    expect($output)->toContain('History is disabled');
});

it('prunes the runs older than the retention period', function (): void {
    keepHistory();

    $row = fn(int $daysAgo): array => [
        'report' => 'all-orders',
        'name' => 'All Orders',
        'params' => '[]',
        'format' => 'csv',
        'filename' => 'all-orders.csv',
        'status' => 'succeeded',
        'started_at' => CarbonImmutable::now()->subDays($daysAgo)->format('Y-m-d H:i:s'),
    ];

    DB::table('report_runs')->insert([$row(1), $row(30), $row(100)]);

    [$status, $output] = console('reports:prune');

    expect($status)->toBe(0)
        ->and($output)->toContain('Pruned 1 run older than 90 days.')
        ->and(RecordedRun::query()->count())->toBe(2);

    console('reports:prune', ['--days' => 7]);

    expect(RecordedRun::query()->count())->toBe(1);
});

it('rejects a retention that is not a number of days', function (): void {
    keepHistory();

    [$status, $output] = console('reports:prune', ['--days' => 'soon']);

    expect($status)->toBe(1)->and($output)->toContain('[--days] must be a whole number of days');
});
