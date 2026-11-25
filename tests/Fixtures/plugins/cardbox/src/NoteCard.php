<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Cardbox;

use RuntimeException;
use Teme\Contracts\Cards\CardChoice;
use Teme\Contracts\Cards\CardInstance;
use Teme\Contracts\Cards\CardRegion;
use Teme\Contracts\Cards\OffersCardChoices;
use Teme\Contracts\Cards\PluginCard;

/**
 * Draws its text and the region's size, with a script core must clean
 * away; its picks are fixed, sized for a 300x600 region, unless a test
 * tells it to throw.
 */
final class NoteCard implements OffersCardChoices, PluginCard
{
    /** Set by a test: choices() throws. */
    public static bool $fails = false;

    public function render(CardInstance $card): string
    {
        $body = is_string($card->values['body'] ?? null) ? $card->values['body'] : '';

        return '<p data-test="note">'.htmlspecialchars($body).' '.($card->region->size ?? '').'</p><script>alert(1)</script>';
    }

    public function choices(string $field, CardRegion $region, string $search = ''): array
    {
        if (self::$fails) {
            throw new RuntimeException('The picks are gone.');
        }

        return array_values(array_filter([
            new CardChoice('tall', 'Tall', '300x600', $region->size === '300x600'),
            new CardChoice('wide', 'Wide', '728x90', $region->size === '728x90'),
        ], fn (CardChoice $choice): bool => $search === '' || str_contains(strtolower($choice->label), strtolower($search))));
    }
}
