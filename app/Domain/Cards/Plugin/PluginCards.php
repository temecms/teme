<?php

/**
 * The cards the running plugins offer for a theme's regions, and their
 * drawing on a public page within a time budget.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Plugin;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\ContributorTooSlow;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Cards\CardChoice;
use Teme\Contracts\Cards\CardInstance;
use Teme\Contracts\Cards\CardRegion;
use Teme\Contracts\Cards\HasCards;
use Teme\Contracts\Cards\OffersCardChoices;
use Teme\Contracts\Cards\PluginCard;
use Teme\Contracts\Plugins\Capability;
use Throwable;

/**
 * The cards the running plugins offer: only a started plugin's whose
 * enabled row grants pages.insert, read from its manifest's
 * contributes.cards. A card draws within a time budget, and so does the
 * page's set of them; one that throws or runs over is recorded as its
 * plugin's fault and draws nothing, as page insertions do (I-3353).
 * Scoped: the page's budget is spent once per request.
 */
final class PluginCards
{
    /** The longest one card may take to draw, in milliseconds. */
    public const int CALL_BUDGET_MS = 100;

    /** The longest every plugin card on a page may take together, in milliseconds. */
    public const int PAGE_BUDGET_MS = 250;

    /** The longest one choices() call may take, in milliseconds. */
    public const int CHOICES_BUDGET_MS = 250;

    /** The most options one choice field lists. */
    public const int MOST_CHOICES = 50;

    /** Nanoseconds spent drawing cards on this page. */
    private int $spent = 0;

    /** @var array{0: string, 1: array<string, PluginCardDefinition>}|null the cards, with what they were read from */
    private ?array $read = null;

    public function __construct(
        private readonly Container $container,
        private readonly LoadedPlugins $loaded,
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly ColumnClock $clock,
    ) {}

    /**
     * Every card the running plugins offer, by key, in plugin code order,
     * then in the order each manifest declares them.
     *
     * @return array<string, PluginCardDefinition>
     */
    public function all(): array
    {
        $running = array_filter($this->loaded->running(), fn (object $plugin): bool => $plugin instanceof HasCards);
        $enabled = $this->records->enabled();
        $signature = implode(',', array_keys($running)).'|'.implode(',', array_map(fn (object $record): string => $record->name.':'.json_encode($record->consent['capabilities'] ?? []), $enabled));

        if ($this->read !== null && $this->read[0] === $signature) {
            return $this->read[1];
        }

        $byCode = [];

        foreach ($enabled as $record) {
            $plugin = $running[$record->name] ?? null;
            $manifest = $this->registry->all()[$record->name]->manifest ?? null;

            if (! $plugin instanceof HasCards || $manifest === null || ! in_array(Capability::PagesInsert->value, $record->consent['capabilities'] ?? [], true)) {
                continue;
            }

            $byCode[(string) $record->code] = $this->definitions((string) $record->name, (string) $record->code, $manifest->label(), $manifest->version(), $manifest->contributes()['cards'] ?? [], $plugin);
        }

        ksort($byCode, SORT_STRING);

        return ($this->read = [$signature, array_merge(...array_values($byCode))])[1];
    }

    /**
     * The card with this key, "{code}:{id}", or null when no running plugin offers it.
     */
    public function find(string $key): ?PluginCardDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The card's markup as its plugin draws it, uncleaned; '' when it
     * throws, runs over its budget, or the page's budget is spent.
     */
    public function draw(PluginCardDefinition $definition, CardInstance $instance): string
    {
        if ($this->spent >= self::PAGE_BUDGET_MS * 1_000_000) {
            $this->fault($definition, new ContributorTooSlow($definition->owner, intdiv($this->spent, 1_000_000), self::PAGE_BUDGET_MS, 'cards'));

            return '';
        }

        $started = $this->clock->nanoseconds();

        try {
            $html = $definition->card->render($instance);
        } catch (Throwable $failure) {
            $this->spent += $this->clock->nanoseconds() - $started;
            $this->fault($definition, $failure);

            return '';
        }

        $took = $this->clock->nanoseconds() - $started;
        $this->spent += $took;

        if ($took > self::CALL_BUDGET_MS * 1_000_000) {
            $this->fault($definition, new ContributorTooSlow($definition->owner, intdiv($took, 1_000_000), self::CALL_BUDGET_MS, 'card'));

            return '';
        }

        return $html;
    }

    /**
     * The options the plugin gives one of the card's choice fields, at
     * most 50; none when the field isn't a choice, the card offers none,
     * or the plugin throws or runs over its budget.
     *
     * @return list<CardChoice>
     */
    public function choices(PluginCardDefinition $definition, string $field, CardRegion $region, string $search = ''): array
    {
        if (! in_array($field, $definition->choiceKeys, true) || ! $definition->card instanceof OffersCardChoices) {
            return [];
        }

        $started = $this->clock->nanoseconds();

        try {
            $given = $definition->card->choices($field, $region, $search);
        } catch (Throwable $failure) {
            $this->fault($definition, $failure);

            return [];
        }

        $took = $this->clock->nanoseconds() - $started;

        if ($took > self::CHOICES_BUDGET_MS * 1_000_000) {
            $this->fault($definition, new ContributorTooSlow($definition->owner, intdiv($took, 1_000_000), self::CHOICES_BUDGET_MS, 'card choices'));

            return [];
        }

        return self::kept($given);
    }

    /**
     * The first choices a plugin gave that are choices with a value that
     * fits; read as mixed, since PHP doesn't hold a plugin to its list's
     * item type.
     *
     * @return list<CardChoice>
     */
    private static function kept(mixed $given): array
    {
        $choices = array_values(array_filter(is_array($given) ? $given : [], fn (mixed $choice): bool => $choice instanceof CardChoice && mb_strlen($choice->value) <= 191));

        return array_slice($choices, 0, self::MOST_CHOICES);
    }

    /**
     * @param  mixed  $declared  the manifest's contributes.cards
     *
     * @return array<string, PluginCardDefinition>
     */
    private function definitions(string $owner, string $code, string $plugin, string $version, mixed $declared, HasCards $instance): array
    {
        try {
            $handlers = $instance->cards();
        } catch (Throwable $failure) {
            $this->container->make(ListenerFaults::class)->record($owner, $owner.'#cards', HasCards::class, $failure);

            return [];
        }

        $definitions = [];

        foreach (is_array($declared) ? $declared : [] as $card) {
            $id = is_array($card) && is_string($card['id'] ?? null) ? $card['id'] : null;
            $handler = $id === null ? null : ($handlers[$id] ?? null);

            if (! $handler instanceof PluginCard) {
                continue;
            }

            $fields = [];
            $choices = [];

            foreach (is_array($card['fields'] ?? null) ? $card['fields'] : [] as $field) {
                $field = is_array($field) ? $field : [];
                $fields[] = DeclaredSettings::cardField($field, $version);

                if (($field['type'] ?? null) === 'choice') {
                    $choices[] = (string) $field['key'];
                }
            }

            $definition = new PluginCardDefinition(
                owner: $owner,
                code: $code,
                plugin: $plugin,
                id: $id,
                label: is_string($card['label'] ?? null) ? $card['label'] : $id,
                description: is_string($card['description'] ?? null) ? $card['description'] : '',
                icon: is_string($card['icon'] ?? null) ? $card['icon'] : 'square',
                keywords: array_values(array_filter(is_array($card['keywords'] ?? null) ? $card['keywords'] : [], is_string(...))),
                fields: $fields,
                choiceKeys: $choices,
                card: $handler,
            );

            $definitions[$definition->key()] = $definition;
        }

        return $definitions;
    }

    private function fault(PluginCardDefinition $definition, Throwable $failure): void
    {
        $this->container->make(ListenerFaults::class)->record($definition->owner, $definition->owner.'#card', PluginCard::class, $failure);
    }
}
