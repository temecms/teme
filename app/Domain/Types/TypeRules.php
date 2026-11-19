<?php

/**
 * What a person's or a file's content type must meet before it is stored
 * (I-411): keys, labels, admin address, features, and a public type's
 * address pattern.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Addresses\AddressPattern;
use App\Domain\Addresses\Paths;
use App\Domain\Fields\FieldRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;

final class TypeRules
{
    private const string KEY = '/^[a-z][a-z0-9_]{0,31}$/';

    private const string SEGMENT = '/^[a-z][a-z0-9-]{0,39}$/';

    private const string ICON = '/^[a-z][a-z0-9-]{0,39}$/';

    /**
     * The first problem per field, keyed by field, in the order key,
     * singular, plural, segment, icon, taxonomies, supports, comments,
     * archive, searchable, pattern, then type. With $replacing, or when
     * $checkKey is false, the key checks are skipped; a stored row with
     * the type's own key never counts as taking its segment. A taxonomy the
     * type names passes when the registry has it, when a saved row has it
     * that the registry hasn't refused (one just made in this transaction),
     * or when it is in $comingTaxonomies (a file's).
     *
     * @param  list<string>  $comingTaxonomies
     *
     * @return array<string, string>
     */
    public static function problems(TypeDefinition $type, ?TypeDefinition $replacing, bool $checkKey = true, array $comingTaxonomies = []): array
    {
        $problems = [];
        $types = app(ContentTypeRegistry::class);
        $taxonomies = app(TaxonomyRegistry::class);

        if ($checkKey && $replacing === null) {
            if (preg_match(self::KEY, $type->key) !== 1) {
                $problems['key'] = __('A key is lower-case letters, digits and underscores, starts with a letter, and is 32 characters at most.');
            } elseif ($types->has($type->key) || DB::table(StoredTypes::TABLE)->where('key', $type->key)->exists()) {
                $problems['key'] = __('There’s already a type with the key :key.', ['key' => $type->key]);
            } elseif (Permission::query()->get(['name'])->contains(fn (Permission $permission): bool => str_starts_with($permission->name, $type->key.'.'))) {
                $problems['key'] = __('The key :key names a group of permissions, so a type can’t have it.', ['key' => $type->key]);
            }
        }

        if (mb_strlen(trim($type->singular)) < 1 || mb_strlen(trim($type->singular)) > 60) {
            $problems['singular'] = __('Give the type a singular name of 60 characters at most.');
        }

        if (mb_strlen(trim($type->plural)) < 1 || mb_strlen(trim($type->plural)) > 60) {
            $problems['plural'] = __('Give the type a plural name of 60 characters at most.');
        }

        if (preg_match(self::SEGMENT, $type->segment) !== 1) {
            $problems['segment'] = __('An admin address is lower-case letters, digits and hyphens, starts with a letter, and is 40 characters at most.');
        } elseif (in_array($type->segment, ContentTypeRegistry::RESERVED, true)) {
            $problems['segment'] = __('/admin/:segment is one of the admin’s own screens.', ['segment' => $type->segment]);
        } else {
            $holder = $types->bySegment($type->segment);

            if ($holder !== null && $holder->key !== $type->key) {
                $problems['segment'] = __('/admin/:segment already shows :plural.', ['segment' => $type->segment, 'plural' => $holder->plural]);
            } else {
                $taxonomy = $taxonomies->bySegment($type->segment);

                if ($taxonomy !== null) {
                    $problems['segment'] = __('/admin/:segment already shows :plural.', ['segment' => $type->segment, 'plural' => $taxonomy->plural]);
                } else {
                    $other = DB::table(StoredTypes::TABLE)
                        ->where('key', '!=', $type->key)
                        ->whereRaw("definition ->> 'segment' = ?", [$type->segment])
                        ->selectRaw("definition ->> 'plural' AS plural")
                        ->value('plural');

                    if ($other !== null) {
                        $problems['segment'] = __('/admin/:segment already shows :plural.', ['segment' => $type->segment, 'plural' => (string) $other]);
                    } else {
                        $stored = DB::table(StoredTaxonomies::TABLE)
                            ->whereRaw("definition ->> 'segment' = ?", [$type->segment])
                            ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
                            ->value('plural');

                        if ($stored !== null) {
                            $problems['segment'] = __('/admin/:segment already shows :plural.', ['segment' => $type->segment, 'plural' => (string) $stored]);
                        }
                    }
                }
            }
        }

        if (preg_match(self::ICON, $type->icon) !== 1) {
            $problems['icon'] = __('An icon is the name of a Lucide icon: lower-case letters, digits and hyphens.');
        }

        $seen = [];

        foreach ($type->taxonomies as $taxonomy) {
            if (isset($seen[$taxonomy])) {
                $problems['taxonomies'] = __('The type names :taxonomy twice.', ['taxonomy' => $taxonomy]);

                break;
            }

            $seen[$taxonomy] = true;

            $made = app(StoredTaxonomies::class)->exists($taxonomy) && ! array_key_exists($taxonomy, $taxonomies->refused());

            if (! $taxonomies->has($taxonomy) && ! $made && ! in_array($taxonomy, $comingTaxonomies, true)) {
                $problems['taxonomies'] = __('There is no taxonomy :taxonomy.', ['taxonomy' => $taxonomy]);

                break;
            }
        }

        $features = app(FieldRegistry::class)->features();

        foreach ($type->supports as $feature) {
            if (! in_array($feature, $features, true)) {
                $problems['supports'] = __('Nothing on this site provides :feature.', ['feature' => $feature]);

                break;
            }
        }

        if (! $type->public) {
            if ($type->comments !== null) {
                $problems['comments'] = __('Only a public type’s items can take comments: they’re shown on each item’s own page.');
            }

            if ($type->archive) {
                $problems['archive'] = __('Only a public type can have an archive.');
            }

            if ($type->searchable) {
                $problems['searchable'] = __('Only a public type can be searched: search results link to each item’s own page.');
            }

            if ($type->pattern !== null) {
                $problems['pattern'] = __('A type that isn’t public has no address pattern.');
            }
        } else {
            $settled = $type->settled();
            $field = $type->pattern === null ? 'segment' : 'pattern';

            try {
                $parsed = AddressPattern::parse((string) $settled->pattern);
                $segment = $parsed->firstFixedSegment();

                // An unchanged first segment already passed, and adding a
                // language refuses a prefix a type holds, so only the
                // router's and the site's own words are asked again: a save
                // that changes no address reads no setting (I-3360).
                $taken = $segment === self::firstSegment($replacing) ? Paths::reserved($segment) : Paths::taken($segment);

                if ($taken) {
                    $problems[$field] = __('Addresses starting /:segment/ are used by the site itself.', [
                        'segment' => $segment,
                    ]);
                }
            } catch (InvalidArgumentException $failure) {
                $problems[$field] = $failure->getMessage();
            }
        }

        if ($problems === []) {
            $refusal = $types->refusal($type->settled()->toContentType(), $replacing?->key);

            if ($refusal !== null) {
                $problems['type'] = $refusal;
            }
        }

        return $problems;
    }

    /**
     * The first fixed segment of a stored type's address, or null for none
     * (a new type, one that isn't public, or a pattern that won't parse).
     */
    private static function firstSegment(?TypeDefinition $type): ?string
    {
        if ($type === null || ! $type->public) {
            return null;
        }

        try {
            return AddressPattern::parse((string) $type->settled()->pattern)->firstFixedSegment();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
