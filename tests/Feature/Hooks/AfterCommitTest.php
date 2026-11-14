<?php

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Hooks\PendingNotifications;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\Fixtures\Hooks\AnnouncingInput;
use Tests\Fixtures\Hooks\AnnouncingOperation;
use Tests\Fixtures\Hooks\Pinged;
use Tests\Fixtures\Hooks\Steps;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->scoped(Steps::class);
});

/**
 * Subscribes a listener to Pinged that counts what it hears, and returns the
 * list it fills: each notification's what, with the transaction level it
 * was heard at.
 */
function hkHeardPings(): ArrayObject
{
    $heard = new ArrayObject;
    hkSubscriber()->on(Pinged::class, function (Pinged $hook) use ($heard): void {
        $heard[] = $hook->what.':'.DB::transactionLevel();
    });

    return $heard;
}

it('delivers an Operation’s notifications after it commits, after its own after-commit work and the caches', function (): void {
    $steps = app(Steps::class);
    hkSubscriber()->on(Pinged::class, function () use ($steps): void {
        $steps->add('heard:'.DB::transactionLevel());
    });

    app(AnnouncingOperation::class)->execute(new AnnouncingInput(what: 'x'));

    expect($steps->all())->toBe(['own', 'cache', 'heard:'.hkBaseLevel()]);
});

it('delivers nothing when the Operation rolls back', function (): void {
    $heard = hkHeardPings();

    expect(fn () => app(AnnouncingOperation::class)->execute(new AnnouncingInput(fail: true)))->toThrow(RuntimeException::class)
        ->and($heard->count())->toBe(0);
});

it('announces nothing when the Operation is refused', function (): void {
    $heard = hkHeardPings();

    expect(fn () => app(AnnouncingOperation::class)->execute(new AnnouncingInput(refuse: true)))->toThrow(AuthorizationException::class)
        ->and($heard->count())->toBe(0);
});

it('waits for the outermost commit when one Operation runs inside another, and delivers each once', function (): void {
    $heard = hkHeardPings();

    app(AnnouncingOperation::class)->execute(new AnnouncingInput(what: 'outer', inner: new AnnouncingInput(what: 'inner')));

    expect($heard->getArrayCopy())->toBe(['inner:'.hkBaseLevel(), 'outer:'.hkBaseLevel()]);
});

it('delivers nothing from an inner Operation when the outer one rolls back after it', function (): void {
    $heard = hkHeardPings();

    expect(fn () => app(AnnouncingOperation::class)->execute(new AnnouncingInput(what: 'outer', inner: new AnnouncingInput(what: 'inner'), fail: true)))
        ->toThrow(RuntimeException::class)
        ->and($heard->count())->toBe(0);
});

it('delivers nothing during an import run', function (): void {
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);
    $heard = hkHeardPings();

    app(ImportMode::class)->during($run->id, fn () => app(AnnouncingOperation::class)->execute(new AnnouncingInput));

    expect($heard->count())->toBe(0);
});

it('reports a notification that can’t be built, and still delivers the others', function (): void {
    Exceptions::fake();
    $heard = hkHeardPings();

    app(AnnouncingOperation::class)->execute(new AnnouncingInput(what: 'built', build: fn (): Pinged => throw new RuntimeException('Cannot build.')));

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Cannot build.');
    expect($heard->getArrayCopy())->toBe(['built:'.hkBaseLevel()]);
});

it('never builds a notification nobody listens for', function (): void {
    $built = false;

    app(AnnouncingOperation::class)->execute(new AnnouncingInput(build: function () use (&$built): Pinged {
        $built = true;

        return new Pinged('x');
    }));

    expect($built)->toBeFalse();
});

it('keeps the change when a listener fails', function (): void {
    hkRecordFaults();
    hkSubscriber()->on(Pinged::class, fn () => throw new RuntimeException('Listener down.'));
    $result = null;

    expect(function () use (&$result): void {
        $result = app(AnnouncingOperation::class)->execute(new AnnouncingInput(what: 'kept'));
    })->not->toThrow(Throwable::class)
        ->and($result)->toBe('kept')
        ->and(hkFaults())->toHaveCount(1);
});

it('refuses a notification announced outside an Operation', function (): void {
    expect(fn () => app(PendingNotifications::class)->add(Pinged::class, fn (): Pinged => new Pinged('x')))
        ->toThrow(LogicException::class, 'Only an Operation announces a change (rule 1): announce it from inside perform().');
});

it('reports a listener’s failure when its fault can’t be recorded, and still tells the others, keeps the result and purges', function (): void {
    Exceptions::fake();
    $folder = cacheOn();
    cacheShown(['/inner/' => ['content:inner'], '/outer/' => ['content:outer']]);
    app()->bind(ListenerFaults::class, fn () => throw new RuntimeException('No faults today.'));
    hkSubscriber('acme/one')->on(Pinged::class, fn () => throw new RuntimeException('Listener down.'));
    $heard = hkHeardPings();

    $result = app(AnnouncingOperation::class)->execute(new AnnouncingInput(
        what: 'outer', purges: ['content:outer'], inner: new AnnouncingInput(what: 'inner', purges: ['content:inner']),
    ));

    expect($result)->toBe('outer')
        ->and($heard->getArrayCopy())->toBe(['inner:'.hkBaseLevel(), 'outer:'.hkBaseLevel()])
        ->and(cacheKept('/inner/', '/outer/'))->toBe([]);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Listener down.');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'No faults today.');
    cacheOff($folder);
});

it('delivers after the page cache’s purge, so a listener finds the purged page already gone', function (): void {
    $folder = cacheOn();
    cacheShown(['/harbour-news/' => ['content:harbour']]);
    $found = [];
    hkSubscriber()->on(Pinged::class, function () use (&$found): void {
        $found = cacheKept('/harbour-news/');
    });

    app(AnnouncingOperation::class)->execute(new AnnouncingInput(purges: ['content:harbour']));

    expect($found)->toBe([]);
    cacheOff($folder);
});
