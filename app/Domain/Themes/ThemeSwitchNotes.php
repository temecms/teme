<?php

/**
 * What switching away from the active theme hides: the cards it brings
 * that its regions hold, each with how many regions hold it.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Cards\Theme\ThemeCards;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Types\CoreTypes;
use Illuminate\Support\Facades\DB;

/**
 * What a theme switch hides (docs/guides/developers/themes/regions.md): a
 * theme's own card draws only while its theme is active, whatever theme
 * comes next, so the count depends on the active theme alone. Each region
 * is read as its working revision has it.
 */
final readonly class ThemeSwitchNotes
{
    public function __construct(private ThemeCards $cards, private RegionDeclarations $declarations) {}

    /**
     * The active theme's cards its regions hold, in its manifest's order:
     * each card's key, its label and how many regions hold it; [] when they
     * hold none.
     *
     * @return list<array{card: string, label: string, regions: int}>
     */
    public function hiddenByLeaving(Theme $active): array
    {
        $own = $this->cards->forTheme($active->code);

        if ($own === []) {
            return [];
        }

        $slugs = array_map(fn (string $region): string => RegionDeclarations::slug($active->code, $region), array_keys($this->declarations->for($active)));
        $documents = DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.working_revision_id')
            ->where('contents.type', CoreTypes::REGION)
            ->whereNull('contents.deleted_at')
            ->whereIn('contents.slug', $slugs)
            ->pluck('content_revisions.document');

        $regions = [];

        foreach ($documents as $document) {
            $decoded = json_decode((string) $document, true);

            foreach (array_unique(ThemeCards::placedIn(is_array($decoded) ? $decoded : [], $active->code)) as $key) {
                $regions[$key] = ($regions[$key] ?? 0) + 1;
            }
        }

        $hidden = [];

        foreach ($own as $key => $card) {
            if (isset($regions[$key])) {
                $hidden[] = ['card' => $key, 'label' => $card->label, 'regions' => $regions[$key]];
            }
        }

        return $hidden;
    }
}
