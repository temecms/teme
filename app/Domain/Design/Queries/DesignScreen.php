<?php

declare(strict_types=1);

namespace App\Domain\Design\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Contents\Models\Content;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Site\ListingViews;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Themes\ManifestValidator;
use App\Domain\Themes\Theme;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Composer\Semver\Semver;
use finfo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use UnexpectedValueException;

/**
 * What Site › Themes shows of the design: the themes with their states, the
 * theme being previewed, the chosen theme's settings by fieldset and its menu
 * places, each value the draft's over the live one, and whether a draft is
 * pending. Also each section's preview address, and a theme's screenshot.
 */
final readonly class DesignScreen
{
    private const string DESIGN_GROUP = 'design';

    /** The image types a screenshot may be, by the extension its name gives. */
    private const array SCREENSHOT_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __construct(
        private ThemeRegistry $themes,
        private SettingsRegistry $registry,
        private SettingsReader $settings,
    ) {}

    /**
     * The screen's props.
     *
     * @return array{
     *     themes: list<array{code: string, name: string, version: string, description: string, usable: bool, errors: list<string>, live: bool, state: string, screenshot: string|null}>,
     *     theme: string,
     *     preview: array{code: string, name: string, version: string, changed: bool}|null,
     *     themeGroup: string|null,
     *     fieldsets: array<string, list<array{key: string, type: string, label: string, description: string, options: array<string, string>, visibleWhen: array<string, mixed>}>>,
     *     values: array{design: array<string, mixed>, theme: array<string, mixed>},
     *     slots: list<array{slot: string, key: string, label: string, navigationId: string}>,
     *     menus: list<array{id: string, name: string}>,
     *     draft: array{by: string|null, at: string}|null,
     * }
     */
    public function data(): array
    {
        $design = $this->values(self::DESIGN_GROUP);
        $liveTheme = $this->settings->string(self::DESIGN_GROUP, 'theme');
        $chosenCode = (string) $design['theme'];
        $chosen = $this->themes->find($chosenCode);
        $chosen = $chosen !== null && $chosen->usable() ? $chosen : null;
        $group = $chosen === null ? null : ThemeSlotSettings::groupKey($chosen->code);
        $definition = $group === null ? null : $this->registry->find($group);
        $themeValues = $definition === null ? [] : $this->values($definition->key);
        $themes = array_values($this->themes->all());
        // The theme the site names first, as people look for it first; the rest as found.
        usort($themes, fn (Theme $a, Theme $b): int => ($b->code === $liveTheme) <=> ($a->code === $liveTheme));

        return [
            'themes' => array_map(fn (Theme $theme): array => [
                'code' => $theme->code,
                'name' => $theme->manifest?->name() ?? $theme->code,
                'version' => $theme->manifest?->version() ?? '',
                'description' => $theme->manifest?->description() ?? '',
                'usable' => $theme->usable(),
                'errors' => $theme->errors,
                'live' => $theme->code === $liveTheme,
                'state' => $this->state($theme, $liveTheme, $chosenCode),
                'screenshot' => $this->screenshot($theme->code) === null ? null : route('themes.screenshot', ['code' => $theme->code]),
            ], $themes),
            'theme' => $chosenCode,
            'preview' => $chosen === null || $chosen->code === $liveTheme ? null : $this->preview($chosen),
            'themeGroup' => $definition?->key,
            'fieldsets' => $this->fieldsets($definition->settings ?? []),
            'values' => ['design' => $design, 'theme' => $themeValues],
            'slots' => $this->slots($definition->settings ?? [], $themeValues),
            'menus' => Navigation::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
                ->map(fn (Navigation $menu): array => ['id' => $menu->id, 'name' => $menu->name])->values()->all(),
            'draft' => $this->draft(),
        ];
    }

    /**
     * The site-relative address a section's Preview opens, with the trailing
     * slash its pattern gives: the home page for Site; where the latest posts
     * are served for Latest posts; the newest item the home page lists for
     * Content; and that item's first term in its type's first hierarchical
     * taxonomy for Archive. '/' when there is none.
     */
    public function previewPath(string $section): string
    {
        return match ($section) {
            'site' => '/',
            'latest' => $this->latestPath(),
            'content' => $this->contentPath(),
            'archive' => $this->archiveTermPath(),
            default => throw new InvalidArgumentException("No design section [{$section}]."),
        };
    }

    /**
     * The file and type of the screenshot a theme declares, installed with
     * this code, usable or not; or null unless the declared path passes the
     * manifest's check, resolves inside the theme's own folder, and holds the
     * image its name says. No path ever comes from the request.
     *
     * @return array{path: string, type: string}|null
     */
    public function screenshot(string $code): ?array
    {
        $theme = $this->themes->find($code);
        $declared = $theme?->manifest?->screenshot() ?? '';

        if ($theme === null || preg_match(ManifestValidator::SCREENSHOT, $declared) !== 1) {
            return null;
        }

        $folder = realpath($theme->path);
        $file = realpath($theme->path.'/'.$declared);

        if ($folder === false || $file === false || ! is_file($file) || ! str_starts_with($file, $folder.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->file($file);
        $named = self::SCREENSHOT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;

        return $type !== false && $type === $named ? ['path' => $file, 'type' => $type] : null;
    }

    /**
     * The theme the design's draft would switch the site to, by its code,
     * usable or not; null when the draft holds no switch.
     */
    public function draftedTheme(): ?string
    {
        $drafted = SettingsGroupRecord::query()->find(self::DESIGN_GROUP)?->draft_values['theme'] ?? null;

        return is_string($drafted) && $drafted !== $this->settings->string(self::DESIGN_GROUP, 'theme') ? $drafted : null;
    }

    /**
     * How Site › Themes names a theme's state: "active", the usable theme the
     * site is drawn with; "previewing", the usable theme the draft would make
     * live; "available", any other usable theme; "incompatible", one made for
     * another Teme; "broken", one with any other error.
     */
    private function state(Theme $theme, string $live, string $chosen): string
    {
        return match (true) {
            $theme->usable() && $theme->code === $live => 'active',
            $theme->usable() && $theme->code === $chosen => 'previewing',
            $theme->usable() => 'available',
            $this->incompatible($theme) => 'incompatible',
            default => 'broken',
        };
    }

    /**
     * Whether the theme's requires.teme is a constraint this Teme doesn't
     * meet. One that can't be read is the manifest's own error: broken.
     */
    private function incompatible(Theme $theme): bool
    {
        $constraint = $theme->manifest?->requiresTeme() ?? '';

        try {
            return $constraint !== '' && ! Semver::satisfies((string) config('teme.version'), $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * The theme the draft would make live: its code, name and version, and
     * whether its own settings or places hold unpublished changes.
     *
     * @return array{code: string, name: string, version: string, changed: bool}
     */
    private function preview(Theme $theme): array
    {
        return [
            'code' => $theme->code,
            'name' => $theme->manifest?->name() ?? $theme->code,
            'version' => $theme->manifest?->version() ?? '',
            'changed' => SettingsGroupRecord::query()->find(ThemeSlotSettings::groupKey($theme->code))?->draft_values !== null,
        ];
    }

    /**
     * A group's values, the draft's over the live ones, key by key.
     *
     * @return array<string, mixed>
     */
    private function values(string $group): array
    {
        $values = $this->settings->group($group);
        $draft = SettingsGroupRecord::query()->find($group)->draft_values ?? [];

        return array_intersect_key($draft, $values) + $values;
    }

    /**
     * The theme's own settings, by fieldset, in manifest order; its slots
     * have none, and are shown as the menus section.
     *
     * @param  list<Setting>  $settings
     *
     * @return array<string, list<array{key: string, type: string, label: string, description: string, options: array<string, string>, visibleWhen: array<string, mixed>}>>
     */
    private function fieldsets(array $settings): array
    {
        $fieldsets = array_fill_keys(Setting::FIELDSETS, []);

        foreach ($settings as $setting) {
            if ($setting->fieldset !== null) {
                $fieldsets[$setting->fieldset][] = [
                    'key' => $setting->key,
                    'type' => $setting->type->value,
                    'label' => $setting->label,
                    'description' => $setting->description === $setting->label ? '' : $setting->description,
                    'options' => $setting->options,
                    'visibleWhen' => $setting->visibleWhen,
                ];
            }
        }

        return $fieldsets;
    }

    /**
     * The theme's menu places, each with the menu it would show.
     *
     * @param  list<Setting>  $settings
     * @param  array<string, mixed>  $values
     *
     * @return list<array{slot: string, key: string, label: string, navigationId: string}>
     */
    private function slots(array $settings, array $values): array
    {
        $slots = [];

        foreach ($settings as $setting) {
            if (str_starts_with($setting->key, ThemeSlotSettings::SETTING_PREFIX)) {
                $slots[] = [
                    'slot' => substr($setting->key, strlen(ThemeSlotSettings::SETTING_PREFIX)),
                    'key' => $setting->key,
                    'label' => $setting->label,
                    'navigationId' => (string) ($values[$setting->key] ?? ''),
                ];
            }
        }

        return $slots;
    }

    /**
     * Who last changed the pending draft, and when, or null when nothing is pending.
     *
     * @return array{by: string|null, at: string}|null
     */
    private function draft(): ?array
    {
        $latest = SettingsGroupRecord::query()
            ->whereNotNull('draft_values')
            ->where(fn ($query) => $query->where('group_key', self::DESIGN_GROUP)->orWhere('group_key', 'like', ThemeSlotSettings::GROUP_PREFIX.'%'))
            ->orderByDesc('draft_updated_at')
            ->first();

        if ($latest === null) {
            return null;
        }

        return [
            'by' => $latest->draft_updated_by === null ? null : User::query()->find($latest->draft_updated_by)?->name,
            'at' => (string) $latest->draft_updated_at?->toIso8601String(),
        ];
    }

    private function latestPath(): string
    {
        $addresses = AddressSettings::current();

        if ($addresses->frontShows !== AddressSettings::FRONT_PAGE || $addresses->postsPageId === '') {
            return '/';
        }

        $postsPage = Content::query()->find($addresses->postsPageId);

        return $postsPage === null ? '/' : (Addresses::relativeUrl($postsPage) ?? '/');
    }

    private function contentPath(): string
    {
        $item = $this->newestListedItem();

        return $item === null ? '/' : (Addresses::relativeUrl($item) ?? '/');
    }

    private function newestListedItem(): ?Content
    {
        $types = ListingTypes::onHome();
        $id = $types === [] ? null : ListingViews::archive($types)->orderByDesc('published_at')->orderByDesc('id')->value('id');

        return $id === null ? null : Content::query()->find($id);
    }

    private function archiveTermPath(): string
    {
        $item = $this->newestListedItem();
        $taxonomy = $item === null ? null : PrimaryCategory::taxonomyFor((string) $item->type);

        if ($item === null || $taxonomy === null) {
            return '/';
        }

        $termId = DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->where('content_terms.content_id', $item->id)
            ->where('content_terms.scope', 'published')
            ->where('terms.taxonomy', $taxonomy)
            ->orderBy('content_terms.position')
            ->value('terms.id');

        $term = $termId === null ? null : Term::query()->find($termId);

        return $term === null ? '/' : (Addresses::relativeUrl($term) ?? '/');
    }
}
