<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use App\Domain\Plugins\Seams\HookSubscriptions;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Hooks\Order;
use Teme\Contracts\Hooks\Subscription;

/**
 * A HookSubscriptions that records what the loader asks of it: each for()'s
 * arguments, each forget() and close(), in order, and gives a Hooks that
 * keeps each subscription. Tests bind it, then call the loader themselves.
 */
final class RecordingSubscriptions implements HookSubscriptions
{
    /** @var list<array{0: string, 1: mixed}> each call, in order, with its arguments */
    public array $calls = [];

    /** @var array<string, list<string>> each plugin's subscription ids, until forgotten */
    public array $subscribed = [];

    public function for(string $plugin, array $capabilities, array $declared, array $quarantined): Hooks
    {
        $this->calls[] = ['for', [$plugin, $capabilities, $declared, $quarantined]];
        $this->subscribed[$plugin] = [];

        $recording = $this;

        return new class($plugin, $recording) implements Hooks
        {
            public function __construct(public readonly string $plugin, private readonly RecordingSubscriptions $recording) {}

            public function on(string $hook, callable $listener, ?Order $order = null): Subscription
            {
                $short = substr((string) strrchr('\\'.$hook, '\\'), 1);
                $id = $this->plugin.'#'.$short.'#'.(count($this->recording->subscribed[$this->plugin] ?? []) + 1);
                $this->recording->subscribed[$this->plugin][] = $id;

                return new Subscription($id);
            }

            public function off(Subscription $subscription): void {}
        };
    }

    public function forget(string $plugin): void
    {
        $this->calls[] = ['forget', $plugin];
        unset($this->subscribed[$plugin]);
    }

    public function close(Hooks $hooks): void
    {
        $this->calls[] = ['close', property_exists($hooks, 'plugin') ? $hooks->plugin : null];
    }

    /**
     * The calls of one kind, by their arguments.
     *
     * @return list<mixed>
     */
    public function calls(string $kind): array
    {
        return array_values(array_map(fn (array $call): mixed => $call[1], array_filter($this->calls, fn (array $call): bool => $call[0] === $kind)));
    }
}
