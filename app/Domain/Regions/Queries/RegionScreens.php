<?php

declare(strict_types=1);

namespace App\Domain\Regions\Queries;

use App\Domain\Cards\CardRegistry;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Regions\RegionWords;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Theme;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Types\CoreTypes;
use Illuminate\Support\Facades\DB;

/**
 * What Content › Regions, the editor's region mode and Site › Themes show
 * (I-098): a theme's regions, each with its state and its content's ID once
 * it has one; the rows kept from other themes, read-only; and, for one item,
 * the region it fills, its theme and the cards it allows, or null for any
 * other item. One query for the rows, however many regions there are.
 */
final readonly class RegionScreens
{
    public function __construct(
        private ActiveTheme $active,
        private ThemeRegistry $themes,
        private RegionDeclarations $declarations,
        private CardRegistry $cards,
    ) {}

    /**
     * The index: the active theme's regions in its manifest's order, and the
     * regions kept from other themes.
     *
     * @return array{theme: array{code: string, name: string}|null, regions: list<array{key: string, label: string, cards: list<string>, cardLabels: list<string>, max: int, limit: string, state: string, contentId: string|null, editedAt: string|null}>, others: list<array{slug: string, label: string, themeName: string|null, state: string, editedAt: string|null}>}
     */
    public function index(): array
    {
        return $this->forTheme($this->active->get());
    }

    /**
     * This theme's regions in its manifest's order, each with its state and
     * its content's ID once it has one, and the regions it leaves unplaced:
     * every other theme's rows, or every row with no theme. Site › Themes
     * reads it for a theme being previewed, whose rows were kept (I-228).
     *
     * @return array{theme: array{code: string, name: string}|null, regions: list<array{key: string, label: string, cards: list<string>, cardLabels: list<string>, max: int, limit: string, state: string, contentId: string|null, editedAt: string|null}>, others: list<array{slug: string, label: string, themeName: string|null, state: string, editedAt: string|null}>}
     */
    public function forTheme(?Theme $theme): array
    {
        $declared = $theme === null ? [] : $this->declarations->for($theme);
        $rows = DB::table('contents')
            ->where('type', CoreTypes::REGION)
            ->whereNull('deleted_at')
            ->orderBy('slug')
            ->get(['id', 'slug', 'title', 'working_revision_id', 'published_revision_id', 'updated_at'])
            ->keyBy('slug');

        $regions = [];

        foreach ($declared as $key => $region) {
            $row = $rows->pull(RegionDeclarations::slug($theme->code, $key));

            $regions[] = [
                'key' => $key,
                'label' => $region['label'],
                'cards' => $region['cards'],
                'cardLabels' => $this->labels($region['cards']),
                'max' => $region['max'],
                'limit' => RegionWords::limit($region['max']),
                'state' => self::state($row),
                'contentId' => $row === null ? null : (string) $row->id,
                'editedAt' => $row === null ? null : (string) $row->updated_at,
            ];
        }

        $others = [];

        foreach ($rows as $slug => $row) {
            $owner = $this->owner((string) $slug);

            $others[] = [
                'slug' => (string) $slug,
                'label' => (string) $row->title,
                'themeName' => $owner?->manifest?->name(),
                'state' => self::state($row),
                'editedAt' => (string) $row->updated_at,
            ];
        }

        return [
            'theme' => $theme === null || $theme->manifest === null ? null : ['code' => $theme->code, 'name' => $theme->manifest->name()],
            'regions' => $regions,
            'others' => $others,
        ];
    }

    /**
     * The region this item fills, for the editor's region mode: its label,
     * its theme's name, the cards it allows and, in words, what it takes; null
     * for an item that isn't a region, or a region no installed theme declares
     * any more.
     *
     * @return array{label: string, themeName: string, cards: list<string>, takes: string}|null
     */
    public function editorMode(string $contentId): ?array
    {
        $slug = DB::table('contents')->where('id', $contentId)->where('type', CoreTypes::REGION)->value('slug');

        if (! is_string($slug)) {
            return null;
        }

        $owner = $this->owner($slug);
        $declared = $owner === null ? null : ($this->declarations->for($owner)[substr($slug, strlen($owner->code) + 2)] ?? null);

        if ($owner === null || $declared === null) {
            return null;
        }

        return [
            'label' => $declared['label'],
            'themeName' => $owner->manifest?->name() ?? $owner->code,
            'cards' => $declared['cards'],
            'takes' => RegionWords::takes($this->labels($declared['cards']), $declared['max']),
        ];
    }

    /**
     * The installed theme whose "{code}--" begins the slug and which declares
     * the rest as a region; a code may itself contain "--".
     */
    private function owner(string $slug): ?Theme
    {
        foreach ($this->themes->all() as $theme) {
            $prefix = $theme->code.'--';

            if (str_starts_with($slug, $prefix) && array_key_exists(substr($slug, strlen($prefix)), $this->declarations->for($theme))) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * The cards' labels, in their order; a name no card is registered under
     * is shown as itself.
     *
     * @param  list<string>  $names
     *
     * @return list<string>
     */
    private function labels(array $names): array
    {
        return array_map(fn (string $name): string => $this->cards->find($name)?->label() ?? $name, $names);
    }

    /**
     * "empty" with no row; "draft" never published; "published"; or
     * "changed", published with a newer working revision.
     */
    private static function state(?object $row): string
    {
        return match (true) {
            $row === null => 'empty',
            $row->published_revision_id === null => 'draft',
            $row->published_revision_id !== $row->working_revision_id => 'changed',
            default => 'published',
        };
    }
}
