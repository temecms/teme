<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use InvalidArgumentException;

/**
 * Every card the site knows. It refuses a card without documentation or with
 * a name already taken, so the generated reference is complete and a
 * document's node type can never be ambiguous.
 */
final class CardRegistry
{
    /** @var array<string, CardType> */
    private array $cards = [];

    /** @var array<string, string> */
    private array $owners = [];

    /**
     * Adds a card. The owner is who registered it: `core`, or in phase 5 a
     * plugin's package name, so uninstalling that plugin knows its cards.
     */
    public function register(CardType $card, string $owner = 'core'): void
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $card->name()) !== 1) {
            throw new InvalidArgumentException("Card name [{$card->name()}] must be lowercase letters, numbers and hyphens.");
        }

        // The browser's editor holds prose nodes, marks and cards in one
        // schema, where two of them may not share a name.
        if (in_array($card->name(), [...DocumentSchema::NODES, ...DocumentSchema::MARKS], true)) {
            throw new InvalidArgumentException("Card name [{$card->name()}] is taken by the prose set.");
        }

        if (isset($this->cards[$card->name()])) {
            throw new InvalidArgumentException("Card [{$card->name()}] is already registered.");
        }

        if (trim($card->label()) === '' || trim($card->description()) === '' || trim($card->since()) === '') {
            throw new InvalidArgumentException("Card [{$card->name()}] must document itself: label, description and since.");
        }

        // Later (I-200): plugins register cards in phase 5, with their package
        // name as the owner (docs/architecture/plugins.md).
        $this->cards[$card->name()] = $card;
        $this->owners[$card->name()] = $owner;
    }

    public function find(string $name): ?CardType
    {
        return $this->cards[$name] ?? null;
    }

    /**
     * Who registered the card: `core` or a plugin's package name, or null
     * when no card has that name.
     */
    public function ownerOf(string $name): ?string
    {
        return $this->owners[$name] ?? null;
    }

    /**
     * Every registered card, in registration order.
     *
     * @return list<CardType>
     */
    public function all(): array
    {
        return array_values($this->cards);
    }

    /**
     * The names of every registered card, which are the node types a
     * document may contain beyond the prose set.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->cards);
    }
}
