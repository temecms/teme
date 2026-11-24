<?php

/**
 * The regions a theme declares in its manifest (study B §3e): each one's
 * label, the cards it allows, every card when the manifest doesn't narrow
 * it, the most top-level blocks it holds, 50 when the manifest doesn't say,
 * the most ManifestValidator allows, and the size it tells its cards.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\Plugin\PluginCardNode;
use App\Domain\Cards\Plugin\PluginCards;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Theme;
use Teme\Contracts\Cards\CardRegion;

final readonly class RegionDeclarations
{
    /** The most blocks a region may hold (ManifestValidator::regions()). */
    public const int MAX = 50;

    /** A region's `cards` when it takes every card. */
    public const string EVERY = '*';

    /** A region's size: width x height in pixels (docs/guides/developers/themes/regions.md). */
    public const string SIZE = '/^[1-9]\d{1,4}x[1-9]\d{1,4}$/';

    public function __construct(private ActiveTheme $active) {}

    /**
     * This theme's regions, slug => declaration, in its manifest's order:
     * `cards` is "*" for a region that takes every card, else the cards it
     * names, core cards by name and plugins' as "{code}:{id}".
     *
     * @return array<string, array{label: string, cards: '*'|list<string>, max: int, size: string|null}>
     */
    public function for(Theme $theme): array
    {
        $declared = [];

        foreach ($theme->manifest?->regions() ?? [] as $slug => $region) {
            $cards = $region['cards'] ?? self::EVERY;
            $size = $region['size'] ?? null;

            $declared[(string) $slug] = [
                'label' => is_string($region['label'] ?? null) ? $region['label'] : (string) $slug,
                'cards' => is_array($cards) ? array_values(array_filter($cards, 'is_string')) : self::EVERY,
                'max' => is_int($region['max'] ?? null) ? $region['max'] : self::MAX,
                'size' => is_string($size) && preg_match(self::SIZE, $size) === 1 ? $size : null,
            ];
        }

        return $declared;
    }

    /**
     * The cards a region takes now, by the name the editor offers them
     * under: for "*", every core card the menus offer and every running
     * plugin's card by its key; else the cards it names.
     *
     * @param  '*'|list<string>  $cards  a declaration's `cards`
     *
     * @return list<string>
     */
    public static function names(string|array $cards): array
    {
        if (is_array($cards)) {
            return $cards;
        }

        $names = [];

        foreach (app(CardRegistry::class)->all() as $card) {
            if ($card->menu()->inMenus && ! $card instanceof PluginCardNode) {
                $names[] = $card->name();
            }
        }

        return [...$names, ...array_keys(app(PluginCards::class)->all())];
    }

    /**
     * The region as its cards are told it, its size read into pixels.
     *
     * @param  array{size: string|null}  $declared
     */
    public static function cardRegion(string $themeCode, string $slug, array $declared): CardRegion
    {
        $size = $declared['size'];
        [$width, $height] = $size === null ? [null, null] : array_map(intval(...), explode('x', $size));

        return new CardRegion($slug, $themeCode, $size, $width, $height);
    }

    /**
     * The active theme's regions, or none when no theme is usable.
     *
     * @return array<string, array{label: string, cards: '*'|list<string>, max: int, size: string|null}>
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
