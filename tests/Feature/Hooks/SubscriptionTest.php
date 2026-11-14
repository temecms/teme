<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Hooks\HookRefused;
use Tests\Fixtures\Hooks\DeprecatedPing;
use Tests\Fixtures\Hooks\Pinged;
use Tests\Fixtures\Hooks\Titled;

it('names each subscription by its plugin, hook and place', function (): void {
    $hooks = hkSubscriber('acme/one');

    expect($hooks->on(Pinged::class, fn () => null)->id)->toBe('acme/one#Pinged#1')
        ->and($hooks->on(Pinged::class, fn () => null)->id)->toBe('acme/one#Pinged#2')
        ->and($hooks->on(Titled::class, fn (string $t): string => $t)->id)->toBe('acme/one#Titled#1');
});

it('removes a subscription by its handle, and only the plugin’s own', function (): void {
    $calls = 0;
    $one = hkSubscriber('acme/one');
    $subscription = $one->on(Pinged::class, function () use (&$calls): void {
        $calls++;
    });

    hkSubscriber('acme/two')->off($subscription);
    app(HookDispatcher::class)->notify(new Pinged('x'));

    expect($calls)->toBe(1);

    $one->off($subscription);
    app(HookDispatcher::class)->notify(new Pinged('x'));

    expect($calls)->toBe(1)->and(app(HookDispatcher::class)->wants(Pinged::class))->toBeFalse();
});

it('takes a subscription made during a dispatch from the next dispatch', function (): void {
    $heard = [];
    $hooks = hkSubscriber();
    $hooks->on(Pinged::class, function () use ($hooks, &$heard): void {
        $heard[] = 'first';

        if (count($heard) === 1) {
            $hooks->on(Pinged::class, function () use (&$heard): void {
                $heard[] = 'later';
            });
        }
    });

    app(HookDispatcher::class)->notify(new Pinged('x'));

    expect($heard)->toBe(['first']);

    app(HookDispatcher::class)->notify(new Pinged('x'));

    expect($heard)->toBe(['first', 'first', 'later']);
});

it('refuses a subscription the plugin may not make', function (string $hook, ?array $declared, array $capabilities, string $words): void {
    expect(fn () => hkSubscriber('acme/one', $capabilities, $declared)->on($hook, fn () => null))
        ->toThrow(HookRefused::class, $words);
})->with([
    'not a hook' => [stdClass::class, null, ['content.read'], 'It listens for stdClass, which isn’t a Teme hook.'],
    'not declared' => [Pinged::class, ['Titled'], ['content.read'], 'It listens for Pinged, which its teme-plugin.json doesn’t list under hooks.'],
    'no capability' => [Pinged::class, ['Pinged'], [], 'It listens for Pinged without asking for content.read in its teme-plugin.json.'],
]);

it('refuses a subscription after the plugin’s register() is over', function (): void {
    $hooks = hkSubscriber();
    $hooks->close();

    expect(fn () => $hooks->on(Titled::class, fn (string $t): string => $t))
        ->toThrow(HookRefused::class, 'It listens for Titled after it finished starting: subscribe in register().');
});

it('skips a listener quarantined for its plugin, and hands back its handle', function (): void {
    $heard = [];
    $hooks = hkSubscriber('acme/one', quarantined: ['acme/one#Pinged#1']);
    $first = $hooks->on(Pinged::class, function () use (&$heard): void {
        $heard[] = 'first';
    });
    $second = $hooks->on(Pinged::class, function () use (&$heard): void {
        $heard[] = 'second';
    });

    app(HookDispatcher::class)->notify(new Pinged('x'));

    expect($first->id)->toBe('acme/one#Pinged#1')
        ->and($second->id)->toBe('acme/one#Pinged#2')
        ->and($heard)->toBe(['second']);
});

it('logs a plugin that listens for a deprecated hook, and still delivers it', function (): void {
    Log::spy();
    $calls = 0;
    $hooks = hkSubscriber('acme/one');
    $hooks->on(DeprecatedPing::class, function () use (&$calls): void {
        $calls++;
    });
    $hooks->on(DeprecatedPing::class, fn () => null);

    app(HookDispatcher::class)->notify(new DeprecatedPing);

    expect($calls)->toBe(1);
    Log::shouldHaveReceived('notice')
        ->with('acme/one listens for DeprecatedPing, which Teme stops sending in 0.6.0. Use Pinged instead.')
        ->once();
});

it('forgets every listener of one owner, and numbers its subscriptions from 1 again when it registers again', function (): void {
    $heard = [];
    $dispatcher = app(HookDispatcher::class);
    $one = hkSubscriber('acme/one');
    $one->on(Pinged::class, function () use (&$heard): void {
        $heard[] = 'one';
    });
    $one->on(ContentPublished::class, fn () => null);
    hkSubscriber('acme/two')->on(Pinged::class, function () use (&$heard): void {
        $heard[] = 'two';
    });

    $dispatcher->forget('acme/one');
    $dispatcher->notify(new Pinged('x'));

    expect($heard)->toBe(['two'])
        ->and($dispatcher->wants(ContentPublished::class))->toBeFalse()
        ->and(hkSubscriber('acme/one')->on(ContentPublished::class, fn () => null)->id)->toBe('acme/one#ContentPublished#1');
});

it('forgets an owner with no listener without a word', function (): void {
    $calls = 0;
    hkSubscriber('acme/two')->on(Pinged::class, function () use (&$calls): void {
        $calls++;
    });

    app(HookDispatcher::class)->forget('acme/none');
    app(HookDispatcher::class)->notify(new Pinged('x'));

    expect($calls)->toBe(1);
});

it('never forgets core’s listeners', function (): void {
    expect(fn () => app(HookDispatcher::class)->forget('core'))
        ->toThrow(LogicException::class, 'Core’s listeners stay while Teme runs: only a plugin’s are forgotten.');
});
