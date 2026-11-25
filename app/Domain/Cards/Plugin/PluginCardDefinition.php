<?php

/**
 * One card a running plugin offers, as its manifest declares it: its key,
 * words, icon, fields and the object that draws it.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Plugin;

use Teme\Contracts\Cards\PluginCard;
use Teme\Contracts\Settings\Setting;

/**
 * One card a running plugin offers, as its manifest declares it under
 * contributes.cards, with the object its HasCards::cards() gives for it.
 */
final readonly class PluginCardDefinition
{
    /**
     * @param  string  $owner  the plugin's Composer name, which its faults are recorded under
     * @param  string  $code  the plugin's code
     * @param  string  $plugin  the plugin's label
     * @param  list<string>  $keywords
     * @param  list<Setting>  $fields  built as plugin settings are; a choice is a text of at most 191 characters
     * @param  list<string>  $choiceKeys  the fields whose options the plugin gives while the card is filled in
     */
    public function __construct(
        public string $owner,
        public string $code,
        public string $plugin,
        public string $id,
        public string $label,
        public string $description,
        public string $icon,
        public array $keywords,
        public array $fields,
        public array $choiceKeys,
        public PluginCard $card,
    ) {}

    /**
     * The card's key, "{code}:{id}", as a region's document stores it.
     */
    public function key(): string
    {
        return "{$this->code}:{$this->id}";
    }

    /**
     * The field with this key, or null.
     */
    public function field(string $key): ?Setting
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
