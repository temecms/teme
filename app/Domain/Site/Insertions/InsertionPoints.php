<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\InsertionContributor;
use Teme\Contracts\Plugins\Capability;
use Throwable;
use UnexpectedValueException;

/**
 * The insertions every contributor gives for one article (I-064): read
 * plugin, then site, then core, each key's first kept. Contributors are
 * the started plugins granted content.insert, those bound here, and those
 * tagged under CONTRIBUTORS. A contributor that throws is recorded as its
 * plugin's fault and skipped, as a failing listener is (I-3353).
 */
final class InsertionPoints
{
    /** The container tag an insertion contributor is registered under. */
    public const string CONTRIBUTORS = 'teme.document.insertions';

    /** @var array<string, int> */
    private const array ORDER = ['plugin' => 1, 'site' => 2, 'core' => 3];

    /** @var list<array{string, InsertionContributor}> owner, contributor */
    private array $bound = [];

    public function __construct(
        private Container $container,
        private LoadedPlugins $loaded,
        private PluginRecords $records,
    ) {}

    /**
     * Registers this contributor for this scope when it was granted
     * content.insert; otherwise leaves it unbound so it is never asked.
     *
     * @param  list<string>  $capabilities
     * @param  string|null  $owner  the plugin its faults are recorded under; its class when none
     */
    public function bind(InsertionContributor $contributor, array $capabilities, ?string $owner = null): void
    {
        if (! self::granted($capabilities)) {
            return;
        }

        $this->bound[] = [$owner ?? $contributor::class, $contributor];
    }

    /**
     * This article's insertions, in the order they were read, each key once.
     *
     * @return list<Insertion>
     */
    public function for(ArticleFacts $article): array
    {
        $kept = [];

        foreach ($this->ordered() as [$owner, $contributor]) {
            try {
                $given = $contributor->insertions($article);
            } catch (Throwable $failure) {
                $this->fault($owner, $failure);

                continue;
            }

            foreach (self::onlyInsertions($given) as $insertion) {
                $kept[$insertion->key] ??= $insertion;
            }
        }

        return array_values($kept);
    }

    /**
     * Every contributor with its owner, in precedence order; one whose
     * precedence() throws is recorded and left out.
     *
     * @return list<array{string, InsertionContributor}>
     */
    private function ordered(): array
    {
        $contributors = [...$this->plugins(), ...$this->bound];

        foreach ($this->container->tagged(self::CONTRIBUTORS) as $contributor) {
            if (! $contributor instanceof InsertionContributor) {
                throw new UnexpectedValueException('Registered as a InsertionContributor, but '.get_debug_type($contributor)." isn't one.");
            }

            $contributors[] = [$contributor::class, $contributor];
        }

        $ranked = [];

        foreach ($contributors as [$owner, $contributor]) {
            try {
                $ranked[] = [self::ORDER[$contributor->precedence()] ?? PHP_INT_MAX, $owner, $contributor];
            } catch (Throwable $failure) {
                $this->fault($owner, $failure);
            }
        }

        usort($ranked, fn (array $one, array $other): int => $one[0] <=> $other[0]);

        return array_map(fn (array $entry): array => [$entry[1], $entry[2]], $ranked);
    }

    /**
     * What a contributor gave, without anything that isn't an Insertion: PHP
     * checks only that its answer is an array, not what the array holds.
     *
     * @return list<Insertion>
     */
    private static function onlyInsertions(mixed $given): array
    {
        return is_array($given) ? array_values(array_filter($given, fn (mixed $insertion): bool => $insertion instanceof Insertion)) : [];
    }

    private function fault(string $owner, Throwable $failure): void
    {
        $this->container->make(ListenerFaults::class)->record($owner, $owner.'#insertions', InsertionContributor::class, $failure);
    }

    /**
     * The plugins started once a process that contribute insertions and
     * whose enabled row grants content.insert, so a fresh scope, as
     * PageWarmer's, still asks them.
     *
     * @return list<array{string, InsertionContributor}>
     */
    private function plugins(): array
    {
        $running = array_filter($this->loaded->running(), fn (object $plugin): bool => $plugin instanceof InsertionContributor);

        if ($running === []) {
            return [];
        }

        $contributors = [];

        foreach ($this->records->enabled() as $record) {
            $plugin = $running[$record->name] ?? null;

            if ($plugin instanceof InsertionContributor && self::granted($record->consent['capabilities'] ?? [])) {
                $contributors[] = [$record->name, $plugin];
            }
        }

        return $contributors;
    }

    /** @param  array<mixed>  $capabilities */
    private static function granted(array $capabilities): bool
    {
        return in_array(Capability::ContentInsert->value, $capabilities, true);
    }
}
