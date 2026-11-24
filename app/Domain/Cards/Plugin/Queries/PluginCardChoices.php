<?php

/**
 * The options a plugin gives one of its card's choice fields, for a card
 * in one region, as the editor asks while the card is filled in.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Plugin\Queries;

use App\Domain\Cards\Plugin\PluginCards;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Types\CoreTypes;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cards\CardChoice;

/**
 * The options a plugin gives one of its card's choice fields while the
 * card is filled in (docs/guides/developers/plugins/cards.md), told the
 * region the card is in and its size; asked within PluginCards' budget.
 */
final readonly class PluginCardChoices
{
    public function __construct(
        private PluginCards $cards,
        private ThemeRegistry $themes,
        private RegionDeclarations $declarations,
    ) {}

    /**
     * The options, each {value, label, hint, fits}; null when the item
     * isn't a region a theme declares or no running plugin offers the card.
     *
     * @return list<array{value: string, label: string, hint: string|null, fits: bool|null}>|null
     */
    public function for(string $contentId, string $card, string $field, string $search): ?array
    {
        $slug = DB::table('contents')->where('id', $contentId)->where('type', CoreTypes::REGION)->whereNull('deleted_at')->value('slug');
        $definition = $this->cards->find($card);

        if (! is_string($slug) || $definition === null) {
            return null;
        }

        foreach ($this->themes->all() as $theme) {
            $prefix = $theme->code.'--';
            $declared = str_starts_with($slug, $prefix) ? ($this->declarations->for($theme)[substr($slug, strlen($prefix))] ?? null) : null;

            if ($declared === null) {
                continue;
            }

            $region = RegionDeclarations::cardRegion($theme->code, substr($slug, strlen($prefix)), $declared);

            return array_map(fn (CardChoice $choice): array => [
                'value' => $choice->value,
                'label' => $choice->label,
                'hint' => $choice->hint,
                'fits' => $choice->fits,
            ], $this->cards->choices($definition, $field, $region, mb_substr($search, 0, 100)));
        }

        return null;
    }
}
