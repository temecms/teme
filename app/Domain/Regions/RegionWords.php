<?php

/**
 * What a theme's region takes, and what a theme's activation leaves to fill,
 * in the words Content › Regions, the editor's region mode and Site › Themes
 * show (I-395): the most blocks it holds, and the cards it allows beside text.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

final class RegionWords
{
    /**
     * The most blocks a region holds: "At most 1 block", "At most 5 blocks".
     */
    public static function limit(int $max): string
    {
        return trans_choice('At most :count block|At most :count blocks', $max);
    }

    /**
     * The line under a region's heading in the editor: "Takes text only.",
     * "Takes text and this card: Callout." or "Takes text and these cards:
     * Callout, Button and Image.", then the most blocks it holds.
     *
     * @param  list<string>  $labels  the cards it allows, by their labels
     */
    public static function takes(array $labels, int $max): string
    {
        $cards = match (count($labels)) {
            0 => __('Takes text only.'),
            1 => __('Takes text and this card: :card.', ['card' => $labels[0]]),
            default => __('Takes text and these cards: :cards.', ['cards' => self::listed($labels)]),
        };

        return $cards.' '.self::limit($max).'.';
    }

    /**
     * The line under a region's heading for a region that takes every card:
     * "Takes text and any card.", then the most blocks it holds.
     */
    public static function takesAny(int $max): string
    {
        return __('Takes text and any card.').' '.self::limit($max).'.';
    }

    /**
     * What Site › Themes says once a theme is activated: "{theme} is live.",
     * then, while some of its regions hold nothing, how many wait to be filled.
     */
    public static function live(string $theme, int $empty): string
    {
        $live = __(':theme is live.', ['theme' => $theme]);

        return $empty === 0
            ? $live
            : $live.' '.trans_choice(':count region stays empty until you fill it in Content › Regions.|:count regions stay empty until you fill them in Content › Regions.', $empty);
    }

    /**
     * Said when a switch leaves the theme's own cards behind: how many are
     * kept, and where.
     *
     * @since next
     */
    public static function keptCards(int $cards): string
    {
        return trans_choice('Kept: :count theme card, under Regions › Other themes.|Kept: :count theme cards, under Regions › Other themes.', $cards);
    }

    /**
     * Two or more words as a sentence lists them: "A and B", "A, B and C".
     *
     * @param  list<string>  $words
     */
    private static function listed(array $words): string
    {
        $last = array_pop($words);

        return implode(', ', $words).' and '.$last;
    }
}
