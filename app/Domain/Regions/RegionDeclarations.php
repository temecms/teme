<?php

/**
 * The regions a theme declares in its manifest (study B §3e): each one's
 * label, the cards it allows, every card when the manifest doesn't narrow
 * it, the most top-level blocks it holds, 50 when the manifest doesn't say,
 * the most ManifestValidator allows, the size it tells its cards, and the
 * group Content › Regions lists it under.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\Plugin\PluginCards;
use App\Domain\Cards\Theme\ThemeCards;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Theme;
use Teme\Contracts\Cards\CardRegion;

final readonly class RegionDeclarations
{
    /** The most blocks a region may hold (ManifestValidator::regions()). */
    public const int MAX = 50;

    /** A region's `cards` when it takes every card. */
    public const string EVERY = '*';

    /**
     * The longest name of the group a region is listed under.
     *
     * @since 1.5.0
     */
    public const int GROUP_LENGTH = 40;

    /** A region's size: width x height in pixels (docs/guides/developers/themes/regions.md). */
    public const string SIZE = '/^[1-9]\d{1,4}x[1-9]\d{1,4}$/';

    /** The narrowest and the widest viewport a region may wait for, in pixels. */
    public const int MIN_WIDTH_LEAST = 200;

    public const int MIN_WIDTH_MOST = 4000;

    public function __construct(private ActiveTheme $active) {}

    /**
     * This theme's regions, slug => declaration, in its manifest's order:
     * `cards` is "*" for a region that takes every card, else the cards it
     * names, core cards by name, plugins' as "{code}:{id}" and the theme's
     * own, which the manifest names by id, as "{theme}:{id}".
     *
     * @return array<string, array{label: string, cards: '*'|list<string>, max: int, size: string|null, group: string|null, minWidth: int|null}>
     */
    public function for(Theme $theme): array
    {
        $declared = [];
        $own = array_keys($theme->manifest?->providedCards() ?? []);

        foreach ($theme->manifest?->regions() ?? [] as $slug => $region) {
            $cards = $region['cards'] ?? self::EVERY;
            $size = $region['size'] ?? null;
            $group = is_string($region['group'] ?? null) ? trim($region['group']) : '';

            $declared[(string) $slug] = [
                'label' => is_string($region['label'] ?? null) ? $region['label'] : (string) $slug,
                'cards' => is_array($cards)
                    ? array_values(array_map(fn (string $card): string => in_array($card, $own, true) ? "{$theme->code}:{$card}" : $card, array_filter($cards, 'is_string')))
                    : self::EVERY,
                'max' => is_int($region['max'] ?? null) ? $region['max'] : self::MAX,
                'size' => is_string($size) && preg_match(self::SIZE, $size) === 1 ? $size : null,
                'group' => $group === '' ? null : $group,
                'minWidth' => is_int($region['minWidth'] ?? null) && $region['minWidth'] >= self::MIN_WIDTH_LEAST && $region['minWidth'] <= self::MIN_WIDTH_MOST ? $region['minWidth'] : null,
            ];
        }

        return $declared;
    }

    /**
     * The cards a region takes now, by the name the editor offers them
     * under: for "*", every core card the menus offer, every running
     * plugin's card and every card the region's theme brings, by its key;
     * else the cards it names.
     *
     * @param  '*'|list<string>  $cards  a declaration's `cards`
     * @param  string|null  $theme  the region's theme's code
     *
     * @return list<string>
     */
    public static function names(string|array $cards, ?string $theme = null): array
    {
        if (is_array($cards)) {
            return $cards;
        }

        $names = [];

        foreach (app(CardRegistry::class)->all() as $card) {
            if ($card->menu()->inMenus) {
                $names[] = $card->name();
            }
        }

        $own = $theme === null ? [] : array_keys(app(ThemeCards::class)->forTheme($theme));

        return [...$names, ...$own, ...array_keys(app(PluginCards::class)->all())];
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
     * @return array<string, array{label: string, cards: '*'|list<string>, max: int, size: string|null, group: string|null, minWidth: int|null}>
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
