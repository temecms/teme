<?php

/**
 * WCAG 2.x contrast between two colours, and the site accent's rules (I-457):
 * one accent, reaching 4.5:1 against the light background with its text
 * reaching 4.5:1 on it, and, for the dark scheme, a lighter version of the
 * same colour derived to meet the same rules there. resources/js/lib/contrast.ts
 * mirrors it, word for word and sum for sum, for the admin's live readout.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Themes\Manifest;
use InvalidArgumentException;

final class Contrast
{
    /** The two colours text on the accent can take: the one that contrasts more wins. */
    public const array ON_COLORS = ['#ffffff', '#111111'];

    /** WCAG AA for normal text. Compared with the unrounded ratio: WCAG doesn't round. */
    public const float MINIMUM = 4.5;

    /** The light background assumed for a theme that sets no `base` token. */
    public const string DEFAULT_BASE = '#ffffff';

    /** The steps darkVariant() raises OKLab lightness by. */
    private const float LIGHTNESS_STEP = 0.01;

    /** The halvings that find the most chroma a lightness keeps inside sRGB. */
    private const int CHROMA_SEARCHES = 20;

    /**
     * The background of each scheme a theme can show, scheme => colour: its
     * `base` token for light, white when it sets none, and its `tokens_dark`
     * base for dark, only when it has one. Core's fallback has only light.
     *
     * @return array<string, string>
     */
    public static function bases(?Manifest $manifest): array
    {
        $bases = ['light' => $manifest?->tokens()['base'] ?? self::DEFAULT_BASE];
        $dark = $manifest?->tokensDark()['base'] ?? null;

        return $dark === null ? $bases : $bases + ['dark' => $dark];
    }

    /**
     * The contrast ratio of two colours, `#rgb` or `#rrggbb` in either case,
     * rounded to two places: 21.0 for black on white.
     */
    public static function ratio(string $hexA, string $hexB): float
    {
        return floor(self::exactRatio($hexA, $hexB) * 100 + 0.5) / 100;
    }

    /**
     * Whichever of ON_COLORS contrasts more with this colour, the first on a tie.
     */
    public static function onColor(string $hex): string
    {
        [$first, $second] = self::ON_COLORS;

        return self::exactRatio($hex, $second) > self::exactRatio($hex, $first) ? $second : $first;
    }

    /**
     * Why this accent can't be used, one sentence per failure: against the
     * light background (I-457; the dark scheme takes darkVariant() of it),
     * then for the text drawn on it. Empty when it passes.
     *
     * @return list<string>
     */
    public static function accentProblems(string $accent, string $lightBase): array
    {
        $problems = [];
        $ratio = self::exactRatio($accent, $lightBase);

        if ($ratio < self::MINIMUM) {
            $problems[] = 'The brand colour reaches only '.self::shown($ratio).' against the light background; it needs 4.5:1.';
        }

        $onAccent = self::exactRatio($accent, self::onColor($accent));

        if ($onAccent < self::MINIMUM) {
            $problems[] = 'Text on the brand colour reaches only '.self::shown($onAccent).'; it needs 4.5:1.';
        }

        return $problems;
    }

    /**
     * The accent for the dark scheme (I-457): the same colour, lightened just
     * enough to reach 4.5:1 against the dark background, with the text
     * onColor() picks for it reaching 4.5:1 on it, as #rrggbb. OKLab lightness
     * rises in steps of 0.01, the hue kept and the chroma cut to what sRGB can
     * show; the first step that passes is the answer, and white ends the walk.
     * An accent that already passes comes back as it is. contrast.ts does the
     * same sums in the same order.
     */
    public static function darkVariant(string $accent, string $darkBase): string
    {
        $accent = '#'.self::digits($accent);

        if (self::passes($accent, $darkBase)) {
            return $accent;
        }

        [$lightness, $a, $b] = self::toOklab($accent);
        $step = 0;

        while (true) {
            $step++;
            $stepped = min(1.0, $lightness + $step * self::LIGHTNESS_STEP);
            $kept = self::chromaKept($stepped, $a, $b);
            $candidate = self::hexOf(self::fromOklab($stepped, $a * $kept, $b * $kept));

            if ($stepped >= 1.0 || self::passes($candidate, $darkBase)) {
                return $candidate;
            }
        }
    }

    /**
     * The accent on each scheme the theme can show, for the document shell
     * and Settings › Design alike: the accent itself on the light background,
     * and darkVariant() of it on the dark one, which is the light base when
     * the theme's dark scheme keeps it; each with the text it takes and its
     * ratio. Dark is null for a theme with no dark scheme.
     *
     * @return array{light: array{accent: string, onAccent: string, ratio: float}, dark: array{accent: string, onAccent: string, ratio: float}|null}
     */
    public static function accentSchemes(string $accent, ?Manifest $manifest): array
    {
        $accent = '#'.self::digits($accent);
        $lightBase = self::bases($manifest)['light'];
        $tokensDark = $manifest?->tokensDark() ?? [];
        $dark = null;

        if ($tokensDark !== []) {
            $darkBase = $tokensDark['base'] ?? $lightBase;
            $variant = self::darkVariant($accent, $darkBase);
            $dark = ['accent' => $variant, 'onAccent' => self::onColor($variant), 'ratio' => self::ratio($variant, $darkBase)];
        }

        return [
            'light' => ['accent' => $accent, 'onAccent' => self::onColor($accent), 'ratio' => self::ratio($accent, $lightBase)],
            'dark' => $dark,
        ];
    }

    private static function passes(string $hex, string $base): bool
    {
        return self::exactRatio($hex, $base) >= self::MINIMUM
            && self::exactRatio($hex, self::onColor($hex)) >= self::MINIMUM;
    }

    private static function exactRatio(string $hexA, string $hexB): float
    {
        [$lighter, $darker] = [max(self::luminance($hexA), self::luminance($hexB)), min(self::luminance($hexA), self::luminance($hexB))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$red, $green, $blue] = self::linear($hex);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /**
     * A colour's six hex digits, lower case, from `#rgb` or `#rrggbb` in either case.
     */
    private static function digits(string $hex): string
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex, $match) !== 1) {
            throw new InvalidArgumentException("[{$hex}] is not a colour written #rgb or #rrggbb.");
        }

        $digits = strlen($match[1]) === 3
            ? implode('', array_map(fn (string $digit): string => $digit.$digit, str_split($match[1])))
            : $match[1];

        return strtolower($digits);
    }

    /**
     * A colour's three channels, linearised.
     *
     * @return array{float, float, float}
     */
    private static function linear(string $hex): array
    {
        [$red, $green, $blue] = array_map(function (string $pair): float {
            $channel = hexdec($pair) / 255;

            return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, str_split(self::digits($hex), 2));

        return [$red, $green, $blue];
    }

    /** @return array{float, float, float} */
    private static function toOklab(string $hex): array
    {
        [$red, $green, $blue] = self::linear($hex);
        $l = (0.4122214708 * $red + 0.5363325363 * $green + 0.0514459929 * $blue) ** (1 / 3);
        $m = (0.2119034982 * $red + 0.6806995451 * $green + 0.1073969566 * $blue) ** (1 / 3);
        $s = (0.0883024619 * $red + 0.2817188376 * $green + 0.6299787005 * $blue) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /** @return array{float, float, float} linear sRGB, which may fall outside 0 to 1 */
    private static function fromOklab(float $lightness, float $a, float $b): array
    {
        $l = $lightness + 0.3963377774 * $a + 0.2158037573 * $b;
        $m = $lightness - 0.1055613458 * $a - 0.0638541728 * $b;
        $s = $lightness - 0.0894841775 * $a - 1.2914855480 * $b;
        [$l, $m, $s] = [$l * $l * $l, $m * $m * $m, $s * $s * $s];

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /** The largest share of the chroma, 0 to 1, that this lightness keeps inside sRGB. */
    private static function chromaKept(float $lightness, float $a, float $b): float
    {
        if (self::inGamut(self::fromOklab($lightness, $a, $b))) {
            return 1.0;
        }

        [$low, $high] = [0.0, 1.0];

        for ($i = 0; $i < self::CHROMA_SEARCHES; $i++) {
            $middle = ($low + $high) / 2;

            if (self::inGamut(self::fromOklab($lightness, $a * $middle, $b * $middle))) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /** @param  array{float, float, float}  $linear */
    private static function inGamut(array $linear): bool
    {
        return min($linear) >= 0 && max($linear) <= 1;
    }

    /** @param  array{float, float, float}  $linear */
    private static function hexOf(array $linear): string
    {
        return '#'.implode('', array_map(function (float $channel): string {
            $channel = min(1.0, max(0.0, $channel));
            $encoded = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055;

            return sprintf('%02x', (int) floor($encoded * 255 + 0.5));
        }, $linear));
    }

    /**
     * A failing ratio as a message states it: cut to two places, never
     * rounded up, so a failing ratio never reads as 4.5:1.
     */
    private static function shown(float $ratio): string
    {
        return (floor($ratio * 100) / 100).':1';
    }
}
