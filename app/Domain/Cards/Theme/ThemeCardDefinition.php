<?php

/**
 * One card a theme brings, as its manifest declares it under
 * cards.provides: its key, words, icon and fields.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Theme;

use Teme\Contracts\Settings\Setting;

/**
 * One card a theme brings, as its manifest declares it under
 * cards.provides (docs/guides/developers/themes/regions.md). The theme's
 * own view draws it, views/cards/own/{key}.blade.php.
 */
final readonly class ThemeCardDefinition
{
    /**
     * @param  string  $theme  the theme's code
     * @param  string  $themeName  the theme's name
     * @param  list<string>  $keywords  more words Add card finds it by
     * @param  list<Setting>  $fields  built as plugin settings are
     */
    public function __construct(
        public string $theme,
        public string $themeName,
        public string $id,
        public string $label,
        public string $description,
        public string $icon,
        public array $keywords,
        public array $fields,
    ) {}

    /**
     * The card's key, "{theme}:{id}", as a region's document stores it.
     */
    public function key(): string
    {
        return "{$this->theme}:{$this->id}";
    }

    /**
     * Its fields' values with each missing one filled from its default.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    public function withDefaults(array $values): array
    {
        foreach ($this->fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                $values[$field->key] = $field->default;
            }
        }

        return $values;
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
