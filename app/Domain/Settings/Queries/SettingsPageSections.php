<?php

/**
 * What a Settings page draws besides its own group's form (I-440): the
 * theme's own number of items a page and the home page on Reading, the
 * brand colour on General, the image widths on Media. Each is null on a
 * page that draws none. It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Design\Contrast;
use App\Domain\Media\MediaVariants;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Manifest;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final readonly class SettingsPageSections
{
    public function __construct(private ActiveTheme $active, private ContentTypeRegistry $types, private SettingsReader $settings) {}

    /**
     * The sections this page draws, as this person sees them, each null
     * where the page has none.
     *
     * @return array{imageWidths: array{label: string, value: string, hint: string}|null, brandColour: array{value: string, themeAccent: string|null, bases: array<string, string>, onColors: list<string>, schemes: array{light: array{accent: string, onAccent: string, ratio: float}, dark: array{accent: string, onAccent: string, ratio: float}|null}|null, version: int, canChange: bool}|null, themePerPage: array{theme: string|null, number: int}|null, homePage: array{frontShows: string, frontPageId: string, postsPageId: string, frontModes: array{latest: string, page: string}, chosenPages: array<string, string>, latestWords: string, pageTypes: list<string>, canChange: bool}|null}
     */
    public function for(string $page, User $viewer): array
    {
        return [
            'imageWidths' => $page === 'media' ? $this->imageWidths() : null,
            'brandColour' => $page === 'general' ? $this->brandColour($viewer) : null,
            'themePerPage' => $page === 'reading' ? $this->themePerPage() : null,
            'homePage' => $page === 'reading' ? $this->homePage($viewer) : null,
        ];
    }

    /**
     * The live theme's name and its own number of items a listing page
     * shows, which Posts per page at 0 leaves in force; core's own number
     * and no name when no theme is usable.
     *
     * @return array{theme: string|null, number: int}
     */
    private function themePerPage(): array
    {
        $manifest = $this->active->get()?->manifest;

        return ['theme' => $manifest?->name(), 'number' => $manifest?->perPage() ?? Manifest::PER_PAGE];
    }

    /**
     * Reading's home page: the addresses group's three settings as they are,
     * the two modes, the chosen pages' titles, the words for the latest list,
     * the types a home page may be, and whether this person may change them.
     *
     * @return array{frontShows: string, frontPageId: string, postsPageId: string, frontModes: array{latest: string, page: string}, chosenPages: array<string, string>, latestWords: string, pageTypes: list<string>, canChange: bool}
     */
    private function homePage(User $viewer): array
    {
        $settings = AddressSettings::current();
        $chosen = array_values(array_filter([$settings->frontPageId, $settings->postsPageId], fn (string $id): bool => $id !== ''));

        return [
            'frontShows' => $settings->frontShows,
            'frontPageId' => $settings->frontPageId,
            'postsPageId' => $settings->postsPageId,
            'frontModes' => [AddressSettings::FRONT_LATEST => AddressSettings::FRONT_LATEST, AddressSettings::FRONT_PAGE => AddressSettings::FRONT_PAGE],
            // The chosen pages' titles, so the pickers can name them.
            'chosenPages' => $chosen === [] ? [] : DB::table('contents')->whereIn('id', $chosen)->pluck('title', 'id')->map(fn (mixed $title): string => (string) $title)->all(),
            'latestWords' => self::latestWords($this->types),
            // Any public type with no archive, as ChangeAddresses checks.
            'pageTypes' => array_values(array_filter(array_keys($settings->patterns), fn (string $type): bool => ! $this->types->get($type)->archive)),
            'canChange' => $viewer->can('settings.addresses.manage'),
        ];
    }

    /**
     * The types the latest list holds, as the Home page names them: "posts",
     * "posts and films"; "items" when none is ticked.
     */
    private static function latestWords(ContentTypeRegistry $types): string
    {
        $plurals = array_map(fn (string $key): string => mb_strtolower($types->get($key)->plural), ListingTypes::onHome());

        return $plurals === [] ? 'items' : Arr::join($plurals, ', ', ' and ');
    }

    /**
     * General's brand colour: the design group's accent as it is live, the
     * live theme's own accent and backgrounds, the two text colours it may
     * take, how each scheme draws it (I-457), the design group's version,
     * and whether this person may change it.
     *
     * @return array{value: string, themeAccent: string|null, bases: array<string, string>, onColors: list<string>, schemes: array{light: array{accent: string, onAccent: string, ratio: float}, dark: array{accent: string, onAccent: string, ratio: float}|null}|null, version: int, canChange: bool}
     */
    private function brandColour(User $viewer): array
    {
        $manifest = $this->active->get()?->manifest;
        $value = $this->settings->string('design', 'accent');

        return [
            'value' => $value,
            'themeAccent' => $manifest?->tokens()['accent'] ?? null,
            'bases' => Contrast::bases($manifest),
            'onColors' => Contrast::ON_COLORS,
            // The server's answer is the authority: it's what the site draws.
            'schemes' => $value === '' ? null : Contrast::accentSchemes($value, $manifest),
            'version' => (int) (SettingsGroupRecord::query()->whereKey('design')->value('version') ?? 0),
            'canChange' => $viewer->can('settings.design.manage'),
        ];
    }

    /**
     * Media's image widths, as information: the widths and the thumbnail
     * every image gets, and the social card an image used as a featured or
     * social image gets, each read from MediaVariants, so the words can't
     * drift from what is made.
     *
     * @return array{label: string, value: string, hint: string}
     */
    private function imageWidths(): array
    {
        $widths = MediaVariants::WIDTHS;
        $widest = array_pop($widths);
        $thumbnail = MediaVariants::THUMBNAIL;
        [$cardWidth, $cardHeight] = MediaVariants::SHARE['social'];

        return [
            'label' => 'Image widths',
            'value' => implode(', ', $widths)." and {$widest} pixels wide, and a {$thumbnail} × {$thumbnail} thumbnail.",
            'hint' => 'Fixed in Teme, not a setting. A width is made only when the original is wider, plus one at the original’s own width, so nothing is enlarged. All are saved as WebP. '
                ."An image used as a featured or social image also gets a {$cardWidth} × {$cardHeight} social card and the crops search engines may show.",
        ];
    }
}
