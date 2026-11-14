<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\ListenerFailed;
use Illuminate\Support\Facades\Exceptions;
use Teme\Contracts\Hooks\Order;
use Tests\Fixtures\Hooks\Pinged;

/**
 * Subscribes each plugin to Pinged in the order given, and returns who heard
 * one notify(), in order.
 *
 * @param  array<string, Order|null>  $plugins
 *
 * @return list<string>
 */
function hkOrderHeard(array $plugins): array
{
    $heard = new ArrayObject;

    foreach ($plugins as $owner => $order) {
        hkSubscriber($owner)->on(Pinged::class, function () use ($heard, $owner): void {
            $heard[] = substr($owner, 5);
        }, $order);
    }

    app(HookDispatcher::class)->notify(new Pinged('x'));

    return $heard->getArrayCopy();
}

it('runs early listeners first and late ones last, then in registration order', function (): void {
    expect(hkOrderHeard([
        'acme/a' => Order::late(),
        'acme/b' => Order::normal(),
        'acme/c' => Order::early(),
        'acme/d' => null,
    ]))->toBe(['c', 'b', 'd', 'a']);
});

it('puts a listener after the plugins it names, and before them', function (): void {
    expect(hkOrderHeard([
        'acme/a' => Order::normal(),
        'acme/b' => Order::normal()->after('acme/c'),
        'acme/c' => Order::normal(),
        'acme/d' => Order::normal()->before('acme/a'),
    ]))->toBe(['c', 'b', 'd', 'a']);
});

it('ignores a name of a plugin that isn’t loaded', function (): void {
    expect(hkOrderHeard([
        'acme/a' => Order::normal()->after('acme/missing'),
        'acme/b' => null,
    ]))->toBe(['a', 'b']);
});

it('falls back to stage and registration order when the names make a loop, and reports it once', function (): void {
    Exceptions::fake();

    expect(hkOrderHeard([
        'acme/a' => Order::normal()->after('acme/b'),
        'acme/b' => Order::normal()->after('acme/a'),
        'acme/c' => null,
    ]))->toBe(['a', 'b', 'c']);

    app(HookDispatcher::class)->notify(new Pinged('again'));

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (ListenerFailed $e): bool => $e->getMessage() === 'Plugins name each other in a loop in after() or before(); those listeners run in registration order.');
});

it('never orders across stages, whatever the names say', function (): void {
    expect(hkOrderHeard([
        'acme/a' => Order::early()->after('acme/b'),
        'acme/b' => Order::late(),
    ]))->toBe(['a', 'b']);
});
