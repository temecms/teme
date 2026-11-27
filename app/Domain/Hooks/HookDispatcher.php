<?php

/**
 * Core's hook dispatcher (I-3353): it keeps every subscription, filled as
 * plugins boot, and calls each listener inside its own try, so a listener
 * that fails is recorded and harms no other. Plugins subscribe through the
 * OwnedHooks it hands each of them; only core dispatches.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Hooks;

use App\Domain\Plugins\Hooks\PluginHookCatalogue;
use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;
use ReflectionClass;
use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\Failure;
use Teme\Contracts\Hooks\Gate;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;
use Teme\Contracts\Hooks\Order;
use Teme\Contracts\Hooks\Resolve;
use Teme\Contracts\Hooks\Transform;
use Teme\Contracts\Hooks\Veto;
use Throwable;

final class HookDispatcher
{
    /** Dispatches nest at most this deep: a listener that causes the change it listens to. */
    private const int MOST_NESTED = 8;

    /** The owner core subscribes as, granted everything and declaring nothing. */
    public const string CORE = 'core';

    /** @var array<class-string, array<string, Listener>> */
    private array $listeners = [];

    /** @var array<class-string, list<Listener>> */
    private array $sorted = [];

    /** @var array<class-string, HookContract> */
    private array $contracts = [];

    /** @var array<string, true> */
    private array $warned = [];

    /** @var array<string, int> */
    private array $places = [];

    private int $depth = 0;

    private int $registered = 0;

    /**
     * Returns a Hooks for one owner: a plugin's Composer name, or core,
     * which passes 'core' and nothing else and so may listen for anything.
     *
     * @param  list<string>  $capabilities  what the owner was granted
     * @param  list<string>|null  $declared  the hooks' short names it declared; null for any
     * @param  list<string>  $quarantined  its subscriptions' IDs that are not to be called
     */
    public function subscriber(string $owner, array $capabilities = [], ?array $declared = null, array $quarantined = []): OwnedHooks
    {
        return new OwnedHooks($this, $owner, $capabilities, $declared, $quarantined);
    }

    /**
     * Returns whether anyone listens for $hook, so that what only a listener
     * needs is read or built only then.
     *
     * @param  class-string  $hook
     */
    public function wants(string $hook): bool
    {
        return ($this->listeners[$hook] ?? []) !== [];
    }

    /**
     * Tells every listener; a listener that fails is recorded, and the rest go on.
     */
    public function notify(Notification $hook): void
    {
        $listeners = $this->enter($hook);

        try {
            foreach ($listeners as $listener) {
                try {
                    ($listener->callback)($hook);
                } catch (Throwable $failure) {
                    $this->record($listener, $hook, $failure);
                }
            }
        } finally {
            $this->depth--;
        }
    }

    /**
     * Returns the hook's value as the last good listener left it: one that
     * fails, or gives a value of the wrong type, is recorded and skipped, or
     * fails the Operation when core marked the hook so.
     *
     * With a budget, a listener over it is recorded and skipped too.
     *
     * @throws ListenerFailed when a listener of a hook marked Failure::FailOperation fails
     */
    public function transform(Transform $hook, ?TimeBudget $budget = null): mixed
    {
        // Read before enter(), whose depth only the finally below gives back.
        $value = $hook->value();
        $failOperation = $this->contract($hook::class)->failure === Failure::FailOperation;
        $listeners = $this->enter($hook);

        try {
            foreach ($listeners as $listener) {
                $spent = $budget?->spentBefore($listener->owner);

                if ($spent !== null) {
                    $this->fault($listener, $hook, $spent, $failOperation);

                    continue;
                }

                $started = $budget?->start() ?? 0;

                try {
                    $next = ($listener->callback)($value, $hook);
                } catch (Throwable $failure) {
                    $budget?->charge($listener->owner, $started);
                    $this->fault($listener, $hook, $failure, $failOperation);

                    continue;
                }

                $slow = $budget?->charge($listener->owner, $started);

                if ($slow !== null) {
                    $this->fault($listener, $hook, $slow, $failOperation);

                    continue;
                }

                if (! TypeCheck::matches($hook::valueType(), $next)) {
                    $this->fault($listener, $hook, $this->wrongType($hook, $hook::valueType(), $next), $failOperation);

                    continue;
                }

                $value = $next;
            }
        } finally {
            $this->depth--;
        }

        return $value;
    }

    /**
     * Returns the first refusal, or null when every listener lets it go
     * ahead. A listener that fails refuses it, to be safe.
     *
     * With a budget, the gate fails open instead: a listener that fails,
     * answers the wrong type or runs over the budget is recorded and passed
     * over, so the change goes ahead.
     */
    public function gate(Gate $hook, ?TimeBudget $budget = null): ?Refusal
    {
        if ($budget !== null) {
            return $this->gateWithin($hook, $budget);
        }

        $listeners = $this->enter($hook);

        try {
            foreach ($listeners as $listener) {
                try {
                    $answer = ($listener->callback)($hook);

                    if ($answer !== null && ! $answer instanceof Veto) {
                        throw $this->wrongType($hook, '?'.Veto::class, $answer);
                    }
                } catch (Throwable $failure) {
                    $this->record($listener, $hook, $failure);

                    return new Refusal($listener->owner, $listener->owner.' couldn’t check this, so it was stopped to be safe.');
                }

                if ($answer instanceof Veto) {
                    return new Refusal($listener->owner, $answer->reason);
                }
            }
        } finally {
            $this->depth--;
        }

        return null;
    }

    /**
     * Returns the first answer, or null when none comes; a listener that
     * fails, or answers with the wrong type, ends it with none.
     */
    public function resolve(Resolve $hook): mixed
    {
        $listeners = $this->enter($hook);

        try {
            foreach ($listeners as $listener) {
                try {
                    $answer = ($listener->callback)($hook);
                } catch (Throwable $failure) {
                    $this->record($listener, $hook, $failure);

                    return null;
                }

                if ($answer === null) {
                    continue;
                }

                if (! TypeCheck::matches($hook::valueType(), $answer)) {
                    $this->record($listener, $hook, $this->wrongType($hook, $hook::valueType(), $answer));

                    return null;
                }

                return $answer;
            }
        } finally {
            $this->depth--;
        }

        return null;
    }

    /**
     * Returns every good item, in listener order: a listener that fails, or
     * gives anything of the wrong type, adds none of its items.
     *
     * @return list<mixed>
     *
     * @throws ListenerFailed when a listener of a hook marked Failure::FailOperation fails
     */
    public function collect(Collect $hook): array
    {
        // Read before enter(), whose depth only the finally below gives back.
        $failOperation = $this->contract($hook::class)->failure === Failure::FailOperation;
        $listeners = $this->enter($hook);
        $items = [];

        try {
            foreach ($listeners as $listener) {
                try {
                    $given = ($listener->callback)($hook);
                } catch (Throwable $failure) {
                    $this->fault($listener, $hook, $failure, $failOperation);

                    continue;
                }

                $wrong = ! is_array($given) || ! array_is_list($given)
                    ? $given
                    : array_values(array_filter($given, fn (mixed $item): bool => ! TypeCheck::matches($hook::itemType(), $item)));

                if ($wrong !== []) {
                    $this->fault($listener, $hook, $this->wrongType($hook, 'list<'.$hook::itemType().'>', $given), $failOperation);

                    continue;
                }

                /** @var list<mixed> $given */
                array_push($items, ...$given);
            }
        } finally {
            $this->depth--;
        }

        return $items;
    }

    /**
     * Delivers what an Operation announced, after its commit: each
     * notification is built, then told; one that can't be built is core's
     * failure, reported, and the others still go.
     *
     * @param  list<array{0: class-string<Notification>, 1: Closure(): Notification}>  $pending
     */
    public function deliver(array $pending): void
    {
        foreach ($pending as [$hook, $build]) {
            try {
                $notification = $build();
            } catch (Throwable $failure) {
                report($failure);

                continue;
            }

            if (! $notification instanceof $hook) {
                report(new ListenerFailed('A notification announced as '.$hook.' was built as '.TypeCheck::of($notification).'.'));

                continue;
            }

            $this->notify($notification);
        }
    }

    /**
     * Adds a subscription, heard from the next dispatch. For OwnedHooks.
     *
     * @param  class-string<Hook>  $hook
     */
    public function add(string $hook, string $id, string $owner, Closure $callback, Order $order): void
    {
        $this->listeners[$hook][$id] = new Listener($id, $owner, $callback, $order, ++$this->registered);
        unset($this->sorted[$hook]);
    }

    /**
     * Removes a subscription, from the next dispatch. For OwnedHooks.
     *
     * @param  class-string<Hook>  $hook
     */
    public function remove(string $hook, string $id): void
    {
        unset($this->listeners[$hook][$id], $this->sorted[$hook]);

        if (($this->listeners[$hook] ?? null) === []) {
            unset($this->listeners[$hook]);
        }
    }

    /**
     * Drops every subscription of one owner, and its count of places, so an
     * owner registered again in the same process numbers from 1 again.
     * Forgetting an owner with none does nothing.
     *
     * @throws LogicException for core, whose listeners last the process
     */
    public function forget(string $owner): void
    {
        if ($owner === self::CORE) {
            throw new LogicException('Core’s listeners stay while Teme runs: only a plugin’s are forgotten.');
        }

        foreach ($this->listeners as $hook => $listeners) {
            $kept = array_filter($listeners, fn (Listener $listener): bool => $listener->owner !== $owner);

            if ($kept === $listeners) {
                continue;
            }

            unset($this->sorted[$hook]);

            if ($kept === []) {
                unset($this->listeners[$hook]);
            } else {
                $this->listeners[$hook] = $kept;
            }
        }

        foreach (array_keys($this->places) as $place) {
            if (str_starts_with($place, $owner.'#')) {
                unset($this->places[$place]);
            }
        }
    }

    /**
     * Returns the next place of an owner's subscriptions to a hook, counting
     * from 1 across every Hooks it was given, so no two share an ID. For OwnedHooks.
     */
    public function nextPlace(string $owner, string $hookName): int
    {
        return $this->places[$owner.'#'.$hookName] = ($this->places[$owner.'#'.$hookName] ?? 0) + 1;
    }

    /**
     * Returns the rules core declared on a hook's class, read once a process.
     *
     * @param  class-string<Hook>  $hook
     */
    public function contract(string $hook): HookContract
    {
        if (! isset($this->contracts[$hook])) {
            $attribute = (new ReflectionClass($hook))->getAttributes(HookContract::class)[0] ?? null;
            $this->contracts[$hook] = $attribute?->newInstance() ?? new HookContract;
        }

        return $this->contracts[$hook];
    }

    /**
     * Logs, once a process, that an owner listens for a hook on its way out.
     *
     * @param  class-string<Hook>  $hook
     */
    public function warnDeprecated(string $owner, string $hook): void
    {
        $contract = $this->contract($hook);

        if ($contract->deprecated === null || isset($this->warned[$owner.'#'.$hook])) {
            return;
        }

        $this->warned[$owner.'#'.$hook] = true;
        $replacement = $contract->use === null ? '' : ' Use '.self::shortName($contract->use).' instead.';

        Log::notice($owner.' listens for '.self::shortName($hook).', which Teme stops sending in '.$contract->removedIn.'.'.$replacement);
    }

    /**
     * Returns a hook's name as a manifest lists it: a core hook's short
     * name, or `{code}:{name}` for a hook an enabled plugin declares
     * (docs/plans/plugin-sockets-3.md).
     *
     * @since 1.0.0
     */
    public function nameOf(string $hook): string
    {
        if (str_starts_with($hook, 'Teme\\Contracts\\')) {
            return self::shortName($hook);
        }

        return app(PluginHookCatalogue::class)->fullName($hook) ?? self::shortName($hook);
    }

    /**
     * Returns a class's name without its namespace, as a manifest names a hook.
     */
    public static function shortName(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }

    /**
     * This hook's listeners, sorted, as a snapshot: a subscription made while
     * they run is heard from the next dispatch. Counts the depth, which the
     * caller's finally gives back, so nothing that can throw runs between
     * the two; too deep, it reports and gives none.
     *
     * @return list<Listener>
     */
    private function enter(Hook $hook): array
    {
        $this->depth++;

        if ($this->depth > self::MOST_NESTED) {
            report(new ListenerFailed('A '.$hook::class.' dispatch nested more than '.self::MOST_NESTED.' deep was skipped.'));

            return [];
        }

        return $this->sorted[$hook::class] ??= ListenerOrder::sort(array_values($this->listeners[$hook::class] ?? []));
    }

    /**
     * Records a listener's failure, and throws it on when core marked the
     * hook as one whose failure fails the Operation.
     *
     * @throws ListenerFailed
     */
    private function fault(Listener $listener, Hook $hook, Throwable $failure, bool $failOperation): void
    {
        $this->record($listener, $hook, $failure);

        if ($failOperation) {
            throw new ListenerFailed($failure->getMessage(), $failure, ['owner' => $listener->owner, 'hook' => $hook::class, 'listener' => $listener->id]);
        }
    }

    /**
     * The gate that fails open, for a budget: each listener is asked within
     * the budget, and one that fails, answers the wrong type or runs over is
     * recorded and passed over; the first Veto still refuses.
     */
    private function gateWithin(Gate $hook, TimeBudget $budget): ?Refusal
    {
        $listeners = $this->enter($hook);

        try {
            foreach ($listeners as $listener) {
                $spent = $budget->spentBefore($listener->owner);

                if ($spent !== null) {
                    $this->record($listener, $hook, $spent);

                    continue;
                }

                $started = $budget->start();

                try {
                    $answer = ($listener->callback)($hook);
                } catch (Throwable $failure) {
                    $budget->charge($listener->owner, $started);
                    $this->record($listener, $hook, $failure);

                    continue;
                }

                $slow = $budget->charge($listener->owner, $started);

                if ($slow !== null) {
                    $this->record($listener, $hook, $slow);

                    continue;
                }

                if ($answer === null) {
                    continue;
                }

                if (! $answer instanceof Veto) {
                    $this->record($listener, $hook, $this->wrongType($hook, '?'.Veto::class, $answer));

                    continue;
                }

                return new Refusal($listener->owner, $answer->reason);
            }
        } finally {
            $this->depth--;
        }

        return null;
    }

    private function wrongType(Hook $hook, string $wanted, mixed $given): ListenerFailed
    {
        return new ListenerFailed('It gave '.TypeCheck::of($given).' where '.self::shortName($hook::class).' wants '.$wanted.'.');
    }

    /**
     * Records a listener's failure with the ListenerFaults bound now, never
     * the one bound when this singleton was built: a binding made after boot
     * must reach it. When that can't be built or record, both failures are
     * reported and the dispatch goes on, so nothing escapes the after-commit
     * work of the Operations still to come.
     */
    private function record(Listener $listener, Hook $hook, Throwable $failure): void
    {
        try {
            app(ListenerFaults::class)->record($listener->owner, $listener->id, $hook::class, $failure);
        } catch (Throwable $unrecorded) {
            report($failure);
            report($unrecorded);
        }
    }
}
