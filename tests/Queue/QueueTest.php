<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Exceptions\RenderException;
use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\History\RecorderContract;
use Aldeebhasan\Panorama\History\RunStatus;
use Aldeebhasan\Panorama\Notifications\ReportFailed;
use Aldeebhasan\Panorama\Notifications\ReportReady;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Queue\CompletedReport;
use Aldeebhasan\Panorama\Queue\ReportQueue;
use Aldeebhasan\Panorama\Queue\RunReport;
use Aldeebhasan\Panorama\Report\Report;
use Aldeebhasan\Panorama\Tests\Fixtures\User;
use Aldeebhasan\Panorama\Tests\Reports\OwnOrders;
use Illuminate\Notifications\AnonymousNotifiable;
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
});

function reader(array $abilities = ['orders']): User
{
    return User::query()->create(['email' => 'ada@example.com', 'abilities' => $abilities]);
}

/**
 * A finished report as a worker would describe it, for the notification tests.
 */
function completed(int $bytes = 120): CompletedReport
{
    Storage::disk('reports')->put('reports/all-orders.csv', str_repeat('x', $bytes));

    return new CompletedReport(
        'all-orders', 'All Orders', 'csv', 'all-orders.csv', 'text/csv', 'reports', 'reports/all-orders.csv', 3, $bytes,
    );
}

/*
|--------------------------------------------------------------------------
| dispatching
|--------------------------------------------------------------------------
*/

it('queues a registered report by its key', function (): void {
    Bus::fake();

    app(Panorama::class)->queue('all-orders')->format('json')->store('reports', 'exports')->dispatch();

    Bus::assertDispatched(RunReport::class, fn(RunReport $job): bool => $job->report === 'all-orders'
        && $job->key === 'all-orders'
        && $job->name === 'All Orders'
        && $job->format === 'json'
        && $job->disk === 'reports'
        && $job->directory === 'exports');
});

it('queues an unregistered report by its class', function (): void {
    Bus::fake();

    app(Panorama::class)->queue(OwnOrders::class, [], reader())->dispatch();

    Bus::assertDispatched(RunReport::class, fn(RunReport $job): bool => $job->report === OwnOrders::class
        && $job->key === 'own-orders'
        && $job->format === 'csv');
});

it('validates the parameters when queued, not in the worker', function (): void {
    Bus::fake();

    expect(fn() => app(Panorama::class)->queue('revenue-by-customer', ['from' => 'nope']))
        ->toThrow(ValidationException::class);

    Bus::assertNothingDispatched();
});

it('authorizes the user when queued', function (): void {
    app(Panorama::class)->queue(OwnOrders::class, [], reader(abilities: []));
})->throws(ReportingException::class, 'not allowed to run the report [own-orders]');

it('checks the format when queued', function (): void {
    app(Panorama::class)->queue('all-orders')->format('pdf');
})->throws(RenderException::class, 'No renderer is registered for [pdf]');

it('refuses to queue an anonymous report', function (): void {
    $report = new class extends Report
    {
        public function query(Datasets $datasets): DatasetQuery
        {
            return $datasets->from('orders');
        }
    };

    app(Panorama::class)->queue($report);
})->throws(ReportingException::class, 'cannot be queued');

it('sends the job to the configured connection and queue unless told otherwise', function (): void {
    config()->set('panorama.queue.connection', 'redis');
    config()->set('panorama.queue.queue', 'reports');
    app()->forgetInstance(ReportQueue::class);

    $configured = app(Panorama::class)->queue('all-orders')->job();
    $overridden = app(Panorama::class)->queue('all-orders')->onConnection('sqs')->onQueue('slow')->delay(60)->job();

    expect($configured->connection)->toBe('redis')
        ->and($configured->queue)->toBe('reports')
        ->and($overridden->connection)->toBe('sqs')
        ->and($overridden->queue)->toBe('slow')
        ->and($overridden->delay)->toBe(60);
});

it('queues notifiable models as identifiers and fetches them fresh', function (): void {
    $user = reader();

    $job = app(Panorama::class)->queue(OwnOrders::class, [], $user)->notify($user)->job();

    $serialized = serialize($job);
    $user->update(['email' => 'lovelace@example.com']);

    /** @var RunReport $restored */
    $restored = unserialize($serialized);

    expect($serialized)->not->toContain('ada@example.com')
        ->and($restored->user)->toBeInstanceOf(User::class)
        ->and($restored->notifiables[0])->toBeInstanceOf(User::class)
        ->and($restored->notifiables[0]->email)->toBe('lovelace@example.com');
});

/*
|--------------------------------------------------------------------------
| running in the worker
|--------------------------------------------------------------------------
*/

it('renders, stores and announces the report', function (): void {
    Notification::fake();
    $user = reader();

    app(Panorama::class)->queue(OwnOrders::class, [], $user)->store(directory: 'exports')->notify($user)->dispatch();

    Storage::disk('reports')->assertExists('exports/own-orders.csv');

    Notification::assertSentTo($user, ReportReady::class, function (ReportReady $ready): bool {
        return $ready->report->path === 'exports/own-orders.csv'
            && $ready->report->filename === 'own-orders.csv'
            && $ready->report->disk === null
            && $ready->report->rows === 3
            && $ready->report->bytes === strlen((string) Storage::disk('reports')->get('exports/own-orders.csv'));
    });
});

it('names the stored file when asked', function (): void {
    app(Panorama::class)->queue('all-orders')->format('html')->named('orders-q1.html')->dispatch();

    Storage::disk('reports')->assertExists('reports/orders-q1.html');
});

it('notifies anyone notifiable, not only models', function (): void {
    Notification::fake();

    app(Panorama::class)->queue('all-orders')->notify(Notification::route('mail', 'finance@example.com'))->dispatch();

    Notification::assertSentTo(new AnonymousNotifiable, ReportReady::class);
});

it('stores the report without notifying when nobody asked', function (): void {
    Notification::fake();

    app(Panorama::class)->queue('all-orders')->dispatch();

    Storage::disk('reports')->assertExists('reports/all-orders.csv');
    Notification::assertNothingSent();
});

it('tells whoever asked when the report fails', function (): void {
    Notification::fake();
    $user = reader();

    app(Datasets::class)->fake('orders', [
        ['id' => 1, 'customer_id' => 'c-1', 'amount' => 'lots', 'created_at' => '2026-01-15'],
    ]);

    expect(fn() => app(Panorama::class)->queue(OwnOrders::class, [], $user)->notify($user)->dispatch())
        ->toThrow(PanoramaException::class);

    Notification::assertNotSentTo($user, ReportReady::class);
    Notification::assertSentTo($user, ReportFailed::class, fn(ReportFailed $failed): bool => $failed->key === 'own-orders'
        && str_contains($failed->error, 'amount'));
});

it('authorizes the user again in the worker', function (): void {
    Notification::fake();
    $user = reader();

    $job = app(Panorama::class)->queue(OwnOrders::class, [], $user)->notify($user)->job();
    $user->update(['abilities' => []]);

    expect(fn() => dispatch(unserialize(serialize($job))))
        ->toThrow(ReportingException::class, 'not allowed');

    Notification::assertSentTo($user, ReportFailed::class);
    Storage::disk('reports')->assertMissing('reports/own-orders.csv');
});

it('records a queued run in the history', function (): void {
    config()->set('panorama.history.enabled', true);
    app()->forgetInstance(RecorderContract::class);
    (include __DIR__ . '/../../database/migrations/create_report_runs_table.php.stub')->up();

    $user = reader();

    app(Panorama::class)->queue(OwnOrders::class, [], $user)->dispatch();

    $run = RecordedRun::query()->sole();

    expect($run->report)->toBe('own-orders')
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->destination)->toBe('storage')
        ->and($run->path)->toBe('reports/own-orders.csv')
        ->and($run->user_id)->toBe((string) $user->id);
});

/*
|--------------------------------------------------------------------------
| the notifications
|--------------------------------------------------------------------------
*/

it('attaches a report small enough to the mail', function (): void {
    $mail = (new ReportReady(completed(bytes: 120)))->toMail(reader());

    expect($mail->subject)->toBe('Your report is ready: All Orders')
        ->and($mail->introLines)->toContain('The report "All Orders" has finished: 3 rows, as CSV.')
        ->and($mail->rawAttachments)->toHaveCount(1)
        ->and($mail->rawAttachments[0]['name'])->toBe('all-orders.csv')
        ->and($mail->rawAttachments[0]['options']['mime'])->toBe('text/csv');
});

it('names the path of a report too large to attach', function (): void {
    $mail = (new ReportReady(completed(bytes: 120), attachUpTo: 100))->toMail(reader());

    expect($mail->rawAttachments)->toBe([])
        ->and($mail->attachments)->toBe([])
        ->and($mail->introLines)->toContain('It is too large to attach, and was saved as reports/all-orders.csv.');
});

it('keeps the reason a report failed out of the mail', function (): void {
    $failed = new ReportFailed('all-orders', 'All Orders', 'csv', 'QueryException: SQLSTATE secret_table');
    $mail = $failed->toMail(reader());

    expect($mail->level)->toBe('error')
        ->and(implode(' ', $mail->introLines))->not->toContain('secret_table')
        ->and($failed->toArray(null))->toBe(['report' => 'all-orders', 'name' => 'All Orders', 'format' => 'csv', 'failed' => true]);
});

it('uses the configured channels and notification classes', function (): void {
    $custom = new class(completed()) extends ReportReady
    {
    };

    config()->set('panorama.queue.channels', ['database']);
    config()->set('panorama.queue.notifications.ready', $custom::class);
    config()->set('panorama.queue.notifications.failed', stdClass::class);
    app()->forgetInstance(ReportQueue::class);

    $queue = app(ReportQueue::class);

    expect($queue->ready(completed()))->toBeInstanceOf($custom::class)
        ->and($queue->ready(completed())->via(null))->toBe(['database'])
        ->and($queue->failed('k', 'K', 'csv', 'e'))->toBeInstanceOf(ReportFailed::class);
});
