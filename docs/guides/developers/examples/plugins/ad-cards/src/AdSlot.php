<?php

declare(strict_types=1);

namespace TemeExamples\AdCards;

use Teme\Contracts\Cards\CardChoice;
use Teme\Contracts\Cards\CardInstance;
use Teme\Contracts\Cards\CardRegion;
use Teme\Contracts\Cards\OffersCardChoices;
use Teme\Contracts\Cards\PluginCard;

/**
 * The ad slot: the ad an editor chose, under an optional label; nothing for
 * an ad that has since been deleted.
 */
final readonly class AdSlot implements OffersCardChoices, PluginCard
{
    /**
     * @param  array<string, array{name: string, size: string, html: string}>  $ads
     */
    public function __construct(private array $ads) {}

    public function render(CardInstance $card): string
    {
        $ad = $this->ads[(string) ($card->values['ad'] ?? '')] ?? null;

        if ($ad === null) {
            return '';
        }

        $label = (string) ($card->values['label'] ?? '');

        return ($label === '' ? '' : '<p class="ad-label">'.htmlspecialchars($label).'</p>').$ad['html'];
    }

    public function choices(string $field, CardRegion $region, string $search = ''): array
    {
        $choices = [];

        foreach ($this->ads as $id => $ad) {
            if ($search === '' || stripos($ad['name'], $search) !== false) {
                $choices[] = new CardChoice($id, $ad['name'], $ad['size'], $region->size === null ? null : $ad['size'] === $region->size);
            }
        }

        // The ads that fit the region first.
        usort($choices, fn (CardChoice $a, CardChoice $b): int => ($b->fits === true) <=> ($a->fits === true));

        return $choices;
    }
}
