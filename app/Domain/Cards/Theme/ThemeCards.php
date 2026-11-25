<?php

/**
 * The cards the installed themes bring for their regions, read from each
 * usable theme's manifest, active or not.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Theme;

use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Themes\Theme;
use App\Domain\Themes\ThemeRegistry;
use InvalidArgumentException;

/**
 * The cards the installed themes bring for their regions
 * (docs/guides/developers/themes/regions.md), every usable theme's, active
 * or not, so a region kept from another theme still validates, compares
 * and opens. A card draws only while its theme is active.
 */
final readonly class ThemeCards
{
    public function __construct(private ThemeRegistry $themes) {}

    /**
     * Every installed theme's cards, by key, "{theme}:{id}".
     *
     * @return array<string, ThemeCardDefinition>
     */
    public function all(): array
    {
        $cards = [];

        foreach ($this->themes->all() as $theme) {
            $cards = [...$cards, ...self::of($theme)];
        }

        return $cards;
    }

    /**
     * The cards of the installed theme with this code, by key; none for a
     * theme that isn't installed or can't be used.
     *
     * @return array<string, ThemeCardDefinition>
     */
    public function forTheme(string $code): array
    {
        $theme = $this->themes->all()[$code] ?? null;

        return $theme === null ? [] : self::of($theme);
    }

    /**
     * The card with this key, or null when no installed theme brings it.
     */
    public function find(string $key): ?ThemeCardDefinition
    {
        [$code] = explode(':', $key, 2);

        return $this->forTheme($code)[$key] ?? null;
    }

    /**
     * The keys of this theme's cards a document holds, at any depth, once
     * for each card placed.
     *
     * @param  array<mixed>  $document
     *
     * @return list<string>
     */
    public static function placedIn(array $document, string $theme): array
    {
        $keys = [];

        foreach (is_array($document['content'] ?? null) ? $document['content'] : [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $key = is_array($node['attrs'] ?? null) && is_string($node['attrs']['card'] ?? null) ? $node['attrs']['card'] : '';

            if (($node['type'] ?? null) === 'theme-card' && str_starts_with($key, $theme.':')) {
                $keys[] = $key;
            }

            array_push($keys, ...self::placedIn($node, $theme));
        }

        return $keys;
    }

    /**
     * This theme's cards, by key, in its manifest's order; none for a theme
     * that can't be used.
     *
     * @return array<string, ThemeCardDefinition>
     */
    public static function of(Theme $theme): array
    {
        if (! $theme->usable() || $theme->manifest === null) {
            return [];
        }

        $cards = [];

        foreach ($theme->manifest->providedCards() as $id => $declared) {
            $fields = [];

            try {
                foreach (is_array($declared['fields'] ?? null) ? $declared['fields'] : [] as $field) {
                    $fields[] = DeclaredSettings::cardField(is_array($field) ? $field : [], $theme->manifest->version());
                }
            } catch (InvalidArgumentException) {
                continue;
            }

            $card = new ThemeCardDefinition(
                theme: $theme->code,
                themeName: $theme->manifest->name(),
                id: (string) $id,
                label: is_string($declared['label'] ?? null) ? $declared['label'] : (string) $id,
                description: is_string($declared['description'] ?? null) ? $declared['description'] : '',
                icon: is_string($declared['icon'] ?? null) ? $declared['icon'] : 'square',
                keywords: is_array($declared['keywords'] ?? null) ? array_values(array_filter($declared['keywords'], 'is_string')) : [],
                fields: $fields,
            );

            $cards[$card->key()] = $card;
        }

        return $cards;
    }
}
