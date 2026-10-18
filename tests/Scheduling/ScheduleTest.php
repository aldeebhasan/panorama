<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Exceptions\RenderException;
use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\Notifications\ReportFailed;
use Aldeebhasan\Panorama\Notifications\ReportReady;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Queue\RunReport;
use Aldeebhasan\Panorama\Report\Report;
use Aldeebhasan\Panorama\Scheduling\ScheduledReport;
use Aldeebhasan\Panorama\Tests\Fixtures\User;
use Aldeebhasan\Panorama\Tests\Reports\OwnOrders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('reports');
    config()->set('panorama.storage.disk', 'reports');

    Schema::create('users', function ($table): void {
        $table->id();
        $table->string('email');
        $table->json('abilities');
    });

    CarbonImmutable::setTestNow('2026-10-01 07:00:00');

    app(Datasets::class)->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function finance(string $email = 'finance@example.com'): User
{
    return User::query()->create(['email' => $email, 'abilities' => []]);
}

/**
 * The scheduled events the package added.
 *
 * @return list<CallbackEvent>
 */
function scheduledReports(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        static fn($event): bool => $event instanceof CallbackEvent && str_starts_with((string) $event->description, 'Report ['),
    ));
}

/*
|--------------------------------------------------------------------------
| defining
|--------------------------------------------------------------------------
*/

it('registers the report on the scheduler under its name', function (): void {
    app(Panorama::class)->schedule('all-orders')->monthlyOn(1, '07:00');

    expect(scheduledReports())->toHaveCount(1)
        ->and(scheduledReports()[0]->description)->toBe('Report [all-orders]')
        ->and(scheduledReports()[0]->getExpression())->toBe('0 7 1 * *');
});

it('keeps chaining on the report through the frequency methods', function (): void {
    $scheduled = app(Panorama::class)->schedule('all-orders')
        ->dailyAt('08:00')
        ->timezone('Europe/Berlin')
        ->format('json')
        ->weekdays();

    expect($scheduled)->toBeInstanceOf(ScheduledReport::class)
        ->and($scheduled->getExpression())->toBe('0 8 * * 1-5')
        ->and($scheduled->event()->timezone)->toBe('Europe/Berlin');
});

it('shows up in schedule:list', function (): void {
    app(Panorama::class)->schedule('all-orders')->daily();

    [, $output] = console('schedule:list');

    expect($output)->toContain('Report [all-orders]');
});

it('refuses an unknown report where it is defined', function (): void {
    app(Panorama::class)->schedule('nope');
})->throws(ReportingException::class, 'No report registered under the key [nope]');

it('refuses an unknown format where it is defined', function (): void {
    app(Panorama::class)->schedule('all-orders')->format('pdf');
})->throws(RenderException::class);

it('refuses an anonymous report', function (): void {
    app(Panorama::class)->schedule(new class extends Report
    {
        public function query(Datasets $datasets): DatasetQuery
        {
            return $datasets->from('orders');
        }
    });
})->throws(ReportingException::class, 'cannot be queued');

/*
|--------------------------------------------------------------------------
| firing
|--------------------------------------------------------------------------
*/

it('queues a run with parameters worked out when it fires', function (): void {
    Bus::fake();

    $scheduled = app(Panorama::class)->schedule('revenue-by-customer')
        ->params(fn(CarbonImmutable $now): array => [
            'from' => $now->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'to' => $now->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ])
        ->format('json')
        ->store('reports', 'monthly')
        ->onQueue('reports')
        ->monthlyOn(1, '07:00');

    $scheduled->event()->run(app());

    Bus::assertDispatched(RunReport::class, fn(RunReport $job): bool => $job->report === 'revenue-by-customer'
        && $job->params === ['from' => '2026-09-01', 'to' => '2026-09-30']
        && $job->format === 'json'
        && $job->disk === 'reports'
        && $job->directory === 'monthly'
        && $job->queue === 'reports'
        && $job->stamp?->format('Y-m-d H:i:s') === '2026-10-01 07:00:00');
});

it('gives the parameters the moment it fired in its own timezone', function (): void {
    Bus::fake();
    $seen = null;

    app(Panorama::class)->schedule('all-orders')
        ->params(function (CarbonImmutable $now) use (&$seen): array {
            $seen = $now;

            return [];
        })
        ->timezone('Asia/Tokyo')
        ->event()
        ->run(app());

    expect($seen?->getTimezone()->getName())->toBe('Asia/Tokyo')
        ->and($seen?->format('H:i'))->toBe('16:00');
});

it('stores each run under its own stamped name', function (): void {
    app(Panorama::class)->schedule('all-orders')->daily()->event()->run(app());

    CarbonImmutable::setTestNow('2026-10-02 07:00:00');
    app(Panorama::class)->schedule('all-orders')->daily()->event()->run(app());

    Storage::disk('reports')->assertExists('reports/all-orders-2026-10-01-070000-a253e820.csv');
    Storage::disk('reports')->assertExists('reports/all-orders-2026-10-02-070000-a253e820.csv');
});

it('keeps apart two schedules of one report firing together', function (): void {
    app(Panorama::class)->schedule('revenue-by-customer')->params(['from' => '2026-09-01', 'to' => '2026-09-30'])->event()->run(app());
    app(Panorama::class)->schedule('revenue-by-customer')->params(['from' => '2026-08-01', 'to' => '2026-08-31'])->event()->run(app());

    expect(Storage::disk('reports')->files('reports'))->toHaveCount(2);
});

it('names the stored file with a closure', function (): void {
    app(Panorama::class)->schedule('all-orders')
        ->named(fn(CarbonImmutable $now): string => 'orders-' . $now->format('Y-m') . '.csv')
        ->event()
        ->run(app());

    Storage::disk('reports')->assertExists('reports/orders-2026-10.csv');
});

it('notifies the people the closure finds when it fires', function (): void {
    Notification::fake();

    $scheduled = app(Panorama::class)->schedule('all-orders')
        ->notify(fn() => User::query()->get())
        ->notify(Notification::route('mail', 'ops@example.com'));

    // Created after the schedule was defined, so only a closure called when
    // it fires can find them.
    $ada = finance('ada@example.com');
    $linus = finance('linus@example.com');

    $scheduled->event()->run(app());

    Notification::assertSentTo([$ada, $linus], ReportReady::class);
    Notification::assertSentOnDemand(ReportReady::class);
});

it('runs without a user, so authorization is not consulted', function (): void {
    app(Panorama::class)->schedule(OwnOrders::class)->event()->run(app());

    Storage::disk('reports')->assertExists('reports/own-orders-2026-10-01-070000-a253e820.csv');
});

it('tells the people it would have notified when the run cannot be queued', function (): void {
    Notification::fake();
    Bus::fake();
    $user = finance();

    $scheduled = app(Panorama::class)->schedule('revenue-by-customer')
        ->params(['from' => 'not a date'])
        ->notify($user);

    expect(fn() => $scheduled->event()->run(app()))->toThrow(ValidationException::class);

    Bus::assertNothingDispatched();
    Notification::assertSentTo($user, ReportFailed::class, fn(ReportFailed $failed): bool => $failed->key === 'revenue-by-customer');
});

it('tells them once when the queued job itself fails', function (): void {
    Notification::fake();
    $user = finance();

    app(Datasets::class)->fake('orders', [
        ['id' => 1, 'customer_id' => 'c-1', 'amount' => 'lots', 'created_at' => '2026-01-15'],
    ]);

    $scheduled = app(Panorama::class)->schedule('all-orders')->notify($user);

    expect(fn() => $scheduled->event()->run(app()))->toThrow(PanoramaException::class);

    Notification::assertSentToTimes($user, ReportFailed::class, 1);
});
