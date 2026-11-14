<?php

declare(strict_types=1);

namespace App\Domain\Hooks;

use Teme\Contracts\Hooks\Stage;

/**
 * Puts a hook's listeners in the order they run: stage by stage, and inside
 * a stage after and before the plugins they name, ties in registration
 * order, never by a number (study C, docs/studies/hooks-and-extensions.md).
 */
final class ListenerOrder
{
    /**
     * Listeners stage by stage; inside a stage, each after the plugins it names
     * in after() and before those it names in before(), ties in registration
     * order. A loop puts that stage back in registration order, reported.
     *
     * @param  list<Listener>  $listeners  in registration order
     *
     * @return list<Listener>
     */
    public static function sort(array $listeners): array
    {
        $sorted = [];

        foreach ([Stage::Early, Stage::Normal, Stage::Late] as $stage) {
            $inStage = array_values(array_filter($listeners, fn (Listener $listener): bool => $listener->order->stage === $stage));
            array_push($sorted, ...self::inStage($inStage));
        }

        return $sorted;
    }

    /**
     * @param  list<Listener>  $listeners
     *
     * @return list<Listener>
     */
    private static function inStage(array $listeners): array
    {
        $count = count($listeners);
        $next = array_fill(0, $count, []);
        $waiting = array_fill(0, $count, 0);

        foreach ($listeners as $a => $first) {
            foreach ($listeners as $b => $then) {
                $named = in_array($first->owner, $then->order->after, true) || in_array($then->owner, $first->order->before, true);

                if ($a !== $b && $first->owner !== $then->owner && $named && ! in_array($b, $next[$a], true)) {
                    $next[$a][] = $b;
                    $waiting[$b]++;
                }
            }
        }

        $ready = array_keys(array_filter($waiting, fn (int $count): bool => $count === 0));
        $out = [];

        while ($ready !== []) {
            sort($ready);
            $at = array_shift($ready);
            $out[] = $listeners[$at];

            foreach ($next[$at] as $b) {
                if (--$waiting[$b] === 0) {
                    $ready[] = $b;
                }
            }
        }

        if (count($out) < $count) {
            report(new ListenerFailed('Plugins name each other in a loop in after() or before(); those listeners run in registration order.'));

            return $listeners;
        }

        return $out;
    }
}
