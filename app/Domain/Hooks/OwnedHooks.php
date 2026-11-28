<?php

/**
 * One owner's way to listen (I-3353): bound to its name, what it was
 * granted, the hooks it declared and its quarantined subscriptions, open
 * until close(). It adds to the dispatcher as on() is called. A plugin's
 * own hook is named `{code}:{name}`, as its listeners declare it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Hooks;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\PluginRecords;
use Closure;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Hooks\HookRefused;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Hooks\Order;
use Teme\Contracts\Hooks\Subscription;
use Teme\Contracts\People\PersonInvited;
use Teme\Contracts\People\PersonJoined;

final class OwnedHooks implements Hooks
{
    /** @var array<string, class-string<Hook>> */
    private array $mine = [];

    private bool $closed = false;

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>|null  $declared
     * @param  list<string>  $quarantined
     */
    public function __construct(
        private readonly HookDispatcher $dispatcher,
        private readonly string $owner,
        private readonly array $capabilities,
        private readonly ?array $declared,
        private readonly array $quarantined,
    ) {}

    public function on(string $hook, callable $listener, ?Order $order = null): Subscription
    {
        $name = $this->declared === null ? HookDispatcher::shortName($hook) : $this->dispatcher->nameOf($hook);

        if ($this->closed) {
            throw new HookRefused("It listens for {$name} after it finished starting: subscribe in register().");
        }

        if (! is_subclass_of($hook, Hook::class)) {
            throw new HookRefused("It listens for {$name}, which isn’t a Teme hook.");
        }

        if ($this->declared !== null && ! in_array($name, $this->declared, true)) {
            throw new HookRefused("It listens for {$name}, which its teme-plugin.json doesn’t list under hooks.");
        }

        $capability = $this->dispatcher->contract($hook)->capability;

        // Core is granted every capability; a plugin only what it asked for.
        if ($capability !== null && $this->owner !== HookDispatcher::CORE && ! in_array($capability, $this->capabilities, true)) {
            throw new HookRefused("It listens for {$name} without asking for {$capability} in its teme-plugin.json.");
        }

        $subscription = new Subscription("{$this->owner}#{$name}#".$this->dispatcher->nextPlace($this->owner, $name));

        $this->dispatcher->warnDeprecated($this->owner, $hook);

        // Older people.read grants keep the plugin running; invite/join wait for the wider stamp.
        if (! in_array($subscription->id, $this->quarantined, true) && $this->mayReceive($hook)) {
            $this->mine[$subscription->id] = $hook;
            $this->dispatcher->add($hook, $subscription->id, $this->owner, Closure::fromCallable($listener), $order ?? Order::normal());
        }

        return $subscription;
    }

    /**
     * PersonInvited and PersonJoined need the wider people.read stamp on the
     * plugin's enabled record; core hears them, and a plugin with no enabled
     * record never does.
     *
     * @param  class-string<Hook>  $hook
     */
    private function mayReceive(string $hook): bool
    {
        if ($this->owner === HookDispatcher::CORE || ! in_array($hook, [PersonInvited::class, PersonJoined::class], true)) {
            return true;
        }

        $record = collect(app(PluginRecords::class)->enabled())->first(fn ($row): bool => $row->name === $this->owner);

        return $record !== null && Consent::coversWiderPeopleRead((array) $record->consent);
    }

    public function off(Subscription $subscription): void
    {
        $hook = $this->mine[$subscription->id] ?? null;

        if ($this->closed || $hook === null) {
            return;
        }

        unset($this->mine[$subscription->id]);
        $this->dispatcher->remove($hook, $subscription->id);
    }

    /**
     * Ends the owner's subscribing: a later on() is refused and off() ignored,
     * so a long-running worker never gathers listeners.
     */
    public function close(): void
    {
        $this->closed = true;
    }
}
