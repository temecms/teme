<?php

/**
 * The regions a theme declares in its manifest (study B §3e): each one's
 * label, the cards it allows, and the most top-level blocks it holds, 50
 * when the manifest doesn't say, the most ManifestValidator allows.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Theme;

final readonly class RegionDeclarations
{
    /** The most blocks a region may hold (ManifestValidator::regions()). */
    public const int MAX = 50;

    public function __construct(private ActiveTheme $active) {}

    /**
     * This theme's regions, slug => declaration, in its manifest's order.
     *
     * @return array<string, array{label: string, cards: list<string>, max: int}>
     */
    public function for(Theme $theme): array
    {
        $declared = [];

        foreach ($theme->manifest?->regions() ?? [] as $slug => $region) {
            $declared[(string) $slug] = [
                'label' => is_string($region['label'] ?? null) ? $region['label'] : (string) $slug,
                'cards' => array_values(array_filter(is_array($region['cards'] ?? null) ? $region['cards'] : [], 'is_string')),
                'max' => is_int($region['max'] ?? null) ? $region['max'] : self::MAX,
            ];
        }

        return $declared;
    }

    /**
     * The active theme's regions, or none when no theme is usable.
     *
     * @return array<string, array{label: string, cards: list<string>, max: int}>
     */
    public function forActive(): array
    {
        $theme = $this->active->get();

        return $theme === null ? [] : $this->for($theme);
    }

    /**
     * A region's content slug, one per theme and region: "{theme}--{region}".
     */
    public static function slug(string $themeCode, string $region): string
    {
        return "{$themeCode}--{$region}";
    }
}
