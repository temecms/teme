<?php

/**
 * The import's phases (I-349): whatever is tagged teme.import.phases, read
 * through the container each time, in order. A phase registered wrongly
 * stops whoever asks, naming it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use App\Domain\Import\ImportRunKind;
use LogicException;

final readonly class ImportPhases
{
    public const string TAG = 'teme.import.phases';

    private const string KEY = '/^[a-z]+(-[a-z]+)*$/';

    /**
     * Phases an earlier release ran and this one doesn't, by key, with the
     * order they had: a run that was at one carries on with the next phase
     * after that order, never from the start.
     *
     * @since next
     *
     * @var array<string, int>
     */
    public const array RETIRED = ['menu-places' => 810, 'widgets' => 890];

    /**
     * Every registered phase, by order.
     *
     * @return list<ImportPhase>
     *
     * @throws LogicException for something that isn't a phase, a malformed key or order, or two phases with one key or one order
     */
    public function all(): array
    {
        $phases = [];
        $keys = [];
        $orders = [];

        foreach (app()->tagged(self::TAG) as $phase) {
            if (! $phase instanceof ImportPhase) {
                throw new LogicException('Registered as an import phase, but '.get_debug_type($phase).' isn’t one.');
            }

            $key = $phase->key();
            $order = $phase->order();

            if (preg_match(self::KEY, $key) !== 1) {
                throw new LogicException("Import phase [{$key}] needs a key of lower-case words joined by hyphens.");
            }

            if ($order < 1) {
                throw new LogicException("Import phase [{$key}] needs an order above 0.");
            }

            if ($phase instanceof ListPhase && $phase instanceof StepPhase) {
                throw new LogicException("Import phase [{$key}] is a list phase or a step, never both.");
            }

            if (isset($keys[$key])) {
                throw new LogicException("Two import phases have the key [{$key}].");
            }

            if (isset($orders[$order])) {
                throw new LogicException("Import phases [{$orders[$order]}] and [{$key}] share the order {$order}.");
            }

            $keys[$key] = true;
            $orders[$order] = $key;
            $phases[] = $phase;
        }

        usort($phases, fn (ImportPhase $a, ImportPhase $b): int => $a->order() <=> $b->order());

        return $phases;
    }

    /**
     * The phase with this key, or null.
     */
    public function find(string $key): ?ImportPhase
    {
        foreach ($this->all() as $phase) {
            if ($phase->key() === $key) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * The first phase a run of this kind does, or null when none is registered.
     */
    public function first(ImportRunKind $kind): ?ImportPhase
    {
        foreach ($this->all() as $phase) {
            if ($phase->runsIn($kind)) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * The next phase a run of this kind does after this one, or null after
     * the last. For a retired key (RETIRED), the next after its order; for
     * any other key no longer registered, the first: resuming is running
     * again, so starting over costs reads, never duplicates (I-332).
     */
    public function after(?string $key, ImportRunKind $kind): ?ImportPhase
    {
        $current = $key === null ? null : $this->find($key);
        $order = $current?->order() ?? ($key === null ? null : (self::RETIRED[$key] ?? null));

        if ($order === null) {
            return $this->first($kind);
        }

        foreach ($this->all() as $phase) {
            if ($phase->order() > $order && $phase->runsIn($kind)) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Returns the phase a run at $key goes on with: that phase, the next
     * after a phase this release retired, or the first for a run not yet
     * at one or at a key no release knew. Null when nothing is left.
     *
     * @since next
     */
    public function resume(?string $key, ImportRunKind $kind): ?ImportPhase
    {
        if ($key !== null && $this->find($key) === null && array_key_exists($key, self::RETIRED)) {
            return $this->after($key, $kind);
        }

        return ($key === null ? null : $this->find($key)) ?? $this->first($kind);
    }
}
