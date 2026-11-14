<?php

declare(strict_types=1);

namespace App\Domain\Addresses\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Feeds\FeedPath;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What Settings › Addresses shows: the public types and taxonomies in
 * registry order with their current patterns and bases, the author base,
 * each type's archive page, and the newest item's details of the type whose
 * address takes the presets.
 * It reads only.
 */
final class AddressScreen
{
    /**
     * The screen's props.
     *
     * @return array{
     *     types: list<array{key: string, singular: string, plural: string, main: bool, pattern: string}>,
     *     taxonomies: list<array{key: string, singular: string, plural: string, base: string}>,
     *     authorBase: string,
     *     example: array<string, string>|null,
     *     archives: list<array{key: string, plural: string, base: string, address: string, feed: string, holder: string|null}>
     * }
     */
    public static function data(): array
    {
        $settings = AddressSettings::current();
        $types = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($settings->hasPattern($type->key)) {
                $types[] = ['key' => $type->key, 'singular' => $type->singular, 'plural' => $type->plural, 'main' => $type->key === ListingTypes::first(), 'pattern' => $settings->patterns[$type->key]];
            }
        }

        $taxonomies = [];

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            if ($settings->hasBase($taxonomy->key)) {
                $taxonomies[] = ['key' => $taxonomy->key, 'singular' => $taxonomy->singular, 'plural' => $taxonomy->plural, 'base' => $settings->bases[$taxonomy->key]];
            }
        }

        $archives = [];

        foreach (TypeArchives::types() as $key) {
            $path = TypeArchives::path($key) ?? '';
            $archives[] = [
                'key' => $key,
                'plural' => app(ContentTypeRegistry::class)->get($key)->plural,
                'base' => $path,
                'address' => Addresses::toUrl($path),
                'feed' => (new FeedPath($path, true))->url(),
                'holder' => TypeArchives::holder($key),
            ];
        }

        return [
            'types' => $types,
            'taxonomies' => $taxonomies,
            'authorBase' => $settings->authorBase,
            'example' => self::example(),
            'archives' => $archives,
        ];
    }

    /**
     * The newest published item of the type whose address takes the presets'
     * token values, for "Your newest post would be at …", or null when there
     * is none.
     *
     * @return array<string, string>|null
     */
    private static function example(): ?array
    {
        $main = ListingTypes::first();

        if ($main === null) {
            return null;
        }

        $row = DB::table('contents')
            ->where('contents.type', $main)->where('contents.status', 'published')->whereNull('contents.deleted_at')
            ->orderByDesc('contents.published_at')->orderByDesc('contents.id')
            // The item's first credit's slug, as its address takes it (I-3366), in the same statement.
            ->selectRaw('(SELECT COALESCE(p.slug, u.slug) FROM content_bylines b JOIN people p ON p.id = b.person_id LEFT JOIN users u ON u.id = p.user_id WHERE b.content_id = contents.id ORDER BY b.position LIMIT 1) AS credited')
            ->selectRaw('(SELECT o.slug FROM users o WHERE o.id = contents.author_id) AS owner')
            ->addSelect(['contents.id', 'contents.slug', 'contents.published_at', 'contents.fields'])
            ->first();

        if ($row === null) {
            return null;
        }

        $date = CarbonImmutable::parse((string) $row->published_at)->setTimezone(app(SettingsReader::class)->string('general', 'timezone'));

        $values = [
            'slug' => (string) $row->slug,
            'id' => mb_strtolower((string) $row->id),
            'year' => $date->format('Y'),
            'month' => $date->format('m'),
            'day' => $date->format('d'),
            'author' => (string) ($row->credited ?? $row->owner ?? ''),
        ];

        // The {category} token: the item's primary category, as its address takes it, for the example (I-869).
        $taxonomy = PrimaryCategory::taxonomyFor($main);
        $fields = (array) json_decode((string) ($row->fields ?? '{}'), true);
        $idsInTaxonomy = $taxonomy === null ? [] : DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->where('content_terms.content_id', $row->id)
            ->where('content_terms.scope', 'published')
            ->where('terms.taxonomy', $taxonomy)
            ->orderBy('content_terms.position')
            ->pluck('terms.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $chosen = PrimaryCategory::pick($idsInTaxonomy, $fields[BuiltInFields::PRIMARY_CATEGORY] ?? null);
        $values['category'] = $chosen === null
            ? 'uncategorized'
            : (string) (DB::table('terms')->where('id', $chosen)->value('slug') ?? 'uncategorized');

        return $values;
    }
}
