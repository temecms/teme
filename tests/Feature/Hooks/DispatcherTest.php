<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\ListenerFailed;
use App\Domain\Hooks\Refusal;
use Illuminate\Support\Facades\Exceptions;
use Teme\Contracts\Hooks\Order;
use Teme\Contracts\Hooks\Veto;
use Tests\Fixtures\Hooks\Answered;
use Tests\Fixtures\Hooks\CheckedTitle;
use Tests\Fixtures\Hooks\Gathered;
use Tests\Fixtures\Hooks\Guarded;
use Tests\Fixtures\Hooks\Misdeclared;
use Tests\Fixtures\Hooks\Pinged;
use Tests\Fixtures\Hooks\Titled;
use Tests\Fixtures\Hooks\Unvalued;

it('tells every listener of a notification, in order, and goes on past one that fails', function (): void {
    hkRecordFaults();
    $heard = [];
    $one = hkSubscriber('acme/one');
    $one->on(Pinged::class, function (Pinged $hook) use (&$heard): void {
        $heard[] = 'one:'.$hook->what;
    });
    $one->on(Pinged::class, fn () => throw new RuntimeException('Out of cheese.'));
    hkSubscriber('acme/two')->on(Pinged::class, function (Pinged $hook) use (&$heard): void {
        $heard[] = 'two:'.$hook->what;
    });

    app(HookDispatcher::class)->notify(new Pinged('hello'));

    expect($heard)->toBe(['one:hello', 'two:hello'])
        ->and(hkFaults())->toBe([['owner' => 'acme/one', 'id' => 'acme/one#Pinged#2', 'hook' => Pinged::class, 'message' => 'Out of cheese.']]);
});

it('passes each listener the value the one before returned, and keeps the value when a listener fails or returns the wrong type', function (): void {
    hkRecordFaults();
    $hooks = hkSubscriber();
    $hooks->on(Titled::class, fn (string $title): string => $title.' one');
    $hooks->on(Titled::class, fn (): int => 7);
    $hooks->on(Titled::class, fn () => throw new RuntimeException('No.'));
    $hooks->on(Titled::class, fn (string $title): string => $title.' four');

    expect(app(HookDispatcher::class)->transform(new Titled('Start')))->toBe('Start one four')
        ->and(array_column(hkFaults(), 'id'))->toBe(['acme/one#Titled#2', 'acme/one#Titled#3']);
});

it('fails the operation when a transform core marked as checking fails', function (): void {
    hkSubscriber()->on(CheckedTitle::class, fn () => throw new RuntimeException('Broken check.'));

    expect(fn () => app(HookDispatcher::class)->transform(new CheckedTitle('Start')))->toThrow(ListenerFailed::class);
});

it('stops at the first veto, and treats a failing gate listener as a veto', function (): void {
    hkRecordFaults();
    $ran = [];
    $one = hkSubscriber('acme/one');
    $passes = $one->on(Guarded::class, fn (): ?Veto => null);
    hkSubscriber('acme/two')->on(Guarded::class, fn (): Veto => new Veto('Needs a summary.'));
    hkSubscriber('acme/three')->on(Guarded::class, function () use (&$ran): ?Veto {
        $ran[] = 'three';

        return null;
    });

    expect(app(HookDispatcher::class)->gate(new Guarded))->toEqual(new Refusal('acme/two', 'Needs a summary.'))
        ->and($ran)->toBe([]);

    $one->off($passes);
    $one->on(Guarded::class, fn () => throw new RuntimeException('Down.'), Order::early());

    expect(app(HookDispatcher::class)->gate(new Guarded))
        ->toEqual(new Refusal('acme/one', 'acme/one couldn’t check this, so it was stopped to be safe.'))
        ->and(array_column(hkFaults(), 'id'))->toBe(['acme/one#Guarded#2']);
});

it('lets a gate through when no listener vetoes', function (): void {
    hkSubscriber('acme/one')->on(Guarded::class, fn (): ?Veto => null);

    expect(app(HookDispatcher::class)->gate(new Guarded))->toBeNull();
});

it('takes the first answer, and gives none when a listener fails before one comes', function (Closure $first, ?string $answer, array $ran): void {
    hkRecordFaults();
    $called = [];
    hkSubscriber('acme/one')->on(Answered::class, $first);
    hkSubscriber('acme/two')->on(Answered::class, function () use (&$called): string {
        $called[] = 'two';

        return 'two';
    });
    hkSubscriber('acme/three')->on(Answered::class, function () use (&$called): string {
        $called[] = 'three';

        return 'three';
    });

    expect(app(HookDispatcher::class)->resolve(new Answered))->toBe($answer)->and($called)->toBe($ran);
})->with([
    'no answer first' => [fn (): ?string => null, 'two', ['two']],
    'a failure first' => [fn () => throw new RuntimeException('Down.'), null, []],
    'the wrong type first' => [fn (): int => 5, null, []],
]);

it('gathers every listener’s items, and drops all of one listener’s items when one is the wrong type', function (): void {
    hkRecordFaults();
    hkSubscriber('acme/one')->on(Gathered::class, fn (): array => ['a', 'b']);
    hkSubscriber('acme/two')->on(Gathered::class, fn (): array => ['c', 3]);
    hkSubscriber('acme/three')->on(Gathered::class, fn (): array => ['d']);

    expect(app(HookDispatcher::class)->collect(new Gathered))->toBe(['a', 'b', 'd'])
        ->and(array_column(hkFaults(), 'owner'))->toBe(['acme/two']);
});

it('says whether anyone listens for a hook', function (): void {
    expect(app(HookDispatcher::class)->wants(Pinged::class))->toBeFalse();
    hkSubscriber()->on(Pinged::class, fn () => null);
    expect(app(HookDispatcher::class)->wants(Pinged::class))->toBeTrue();
});

it('stops a dispatch nested too deep, and reports it', function (): void {
    Exceptions::fake();
    $runs = 0;
    hkSubscriber()->on(Pinged::class, function (Pinged $hook) use (&$runs): void {
        $runs++;
        app(HookDispatcher::class)->notify($hook);
    });

    app(HookDispatcher::class)->notify(new Pinged('again'));

    expect($runs)->toBe(8);
    Exceptions::assertReported(fn (ListenerFailed $e): bool => str_contains($e->getMessage(), 'nested'));
});

it('gives the depth back when a transform’s value or a collect’s contract can’t be read, so later dispatches are still heard', function (): void {
    $dispatcher = app(HookDispatcher::class);
    $heard = [];
    hkSubscriber()->on(Pinged::class, function (Pinged $hook) use (&$heard): void {
        $heard[] = $hook->what;
    });

    foreach (range(1, 9) as $ignored) {
        expect(fn () => $dispatcher->transform(new Unvalued))->toThrow(RuntimeException::class, 'No value.')
            ->and(fn () => $dispatcher->collect(new Misdeclared))->toThrow(TypeError::class);
    }

    $dispatcher->notify(new Pinged('still'));

    expect($heard)->toBe(['still']);
});
