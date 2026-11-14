<?php

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Addresses\AddressPattern;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a person's or a file's taxonomy must meet before it is stored
 * (I-414): keys, labels, admin address, the types that use it, and a public
 * taxonomy's address base.
 */
final class TaxonomyRules
{
    /**
     * Query keys a content list reads, which a taxonomy's key (?genre=12)
     * must not take.
     *
     * @var list<string>
     */
    public const array RESERVED_KEYS = ['tab', 'sort', 'direction', 'search', 'in', 'author', 'review', 'month', 'from', 'to', 'after', 'before', 'page'];

    private const string KEY = '/^[a-z][a-z0-9_]{0,31}$/';

    private const string SEGMENT = '/^[a-z][a-z0-9-]{0,39}$/';

    private const string ICON = '/^[a-z][a-z0-9-]{0,39}$/';

    private const string BASE = '/^[a-z0-9][a-z0-9-]{0,59}$/';

    /**
     * The first problem per field, keyed by field, in the order key,
     * singular, plural, segment, icon, types, base, then taxonomy. With
     * $replacing, or when $checkKey is false, the key checks are skipped;
     * a stored row with the taxonomy's own key never counts as taking its
     * segment.
     *
     * @param  list<string>  $types  the listed types that use the taxonomy
     *
     * @return array<string, string>
     */
    public static function problems(TaxonomyDefinition $taxonomy, ?TaxonomyDefinition $replacing, array $types, bool $checkKey = true): array
    {
        $problems = [];
        $registry = app(TaxonomyRegistry::class);
        $typeRegistry = app(ContentTypeRegistry::class);

        if ($checkKey && $replacing === null) {
            if (preg_match(self::KEY, $taxonomy->key) !== 1) {
                $problems['key'] = __('A key is lower-case letters, digits and underscores, starts with a letter, and is 32 characters at most.');
            } elseif ($registry->has($taxonomy->key) || app(StoredTaxonomies::class)->exists($taxonomy->key)) {
                $problems['key'] = __('There’s already a taxonomy with the key :key.', ['key' => $taxonomy->key]);
            } elseif (in_array($taxonomy->key, [...self::RESERVED_KEYS, ...AddressPattern::TOKENS], true)) {
                $problems['key'] = __('The key :key is used by the site’s own lists and addresses, so a taxonomy can’t have it.', ['key' => $taxonomy->key]);
            }
        }

        if (mb_strlen(trim($taxonomy->singular)) < 1 || mb_strlen(trim($taxonomy->singular)) > 60) {
            $problems['singular'] = __('Give the taxonomy a singular name of 60 characters at most.');
        }

        if (mb_strlen(trim($taxonomy->plural)) < 1 || mb_strlen(trim($taxonomy->plural)) > 60) {
            $problems['plural'] = __('Give the taxonomy a plural name of 60 characters at most.');
        }

        $segment = self::segmentProblem($taxonomy);

        if ($segment !== null) {
            $problems['segment'] = $segment;
        }

        if (preg_match(self::ICON, $taxonomy->icon) !== 1) {
            $problems['icon'] = __('An icon is the name of a Lucide icon: lower-case letters, digits and hyphens.');
        }

        $seen = [];

        foreach ($types as $type) {
            if ($typeRegistry->has($type)) {
                if (! $typeRegistry->get($type)->listed) {
                    $problems['types'] = __(':plural have no list of their own, so they can’t use a taxonomy.', ['plural' => $typeRegistry->get($type)->plural]);

                    break;
                }

                if (isset($seen[$type])) {
                    $problems['types'] = __('The taxonomy names :plural twice.', ['plural' => $typeRegistry->get($type)->plural]);

                    break;
                }

                $seen[$type] = true;
            } elseif (! DB::table(StoredTypes::TABLE)->where('key', $type)->exists()) {
                // A saved type the registry refuses is judged by the Operation,
                // which says whether its row can be read.
                $problems['types'] = __('There is no type :type.', ['type' => $type]);

                break;
            }
        }

        $base = $taxonomy->public && ($replacing === null || ! $replacing->public)
            ? self::baseProblem($taxonomy, $replacing)
            : null;

        if ($base !== null) {
            $problems['base'] = $base;
        }

        if ($problems === []) {
            $refusal = $registry->refusal($taxonomy->settled()->toTaxonomy(), $replacing?->key);

            if ($refusal !== null) {
                $problems['taxonomy'] = $refusal;
            }
        }

        return $problems;
    }

    private static function segmentProblem(TaxonomyDefinition $taxonomy): ?string
    {
        if (preg_match(self::SEGMENT, $taxonomy->segment) !== 1) {
            return __('An admin address is lower-case letters, digits and hyphens, starts with a letter, and is 40 characters at most.');
        }

        if (in_array($taxonomy->segment, ContentTypeRegistry::RESERVED, true)) {
            return __('/admin/:segment is one of the admin’s own screens.', ['segment' => $taxonomy->segment]);
        }

        $holder = app(ContentTypeRegistry::class)->bySegment($taxonomy->segment);

        if ($holder !== null) {
            return __('/admin/:segment already shows :plural.', ['segment' => $taxonomy->segment, 'plural' => $holder->plural]);
        }

        $storedType = DB::table(StoredTypes::TABLE)
            ->whereRaw("definition ->> 'segment' = ?", [$taxonomy->segment])
            ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
            ->value('plural');

        if ($storedType !== null) {
            return __('/admin/:segment already shows :plural.', ['segment' => $taxonomy->segment, 'plural' => (string) $storedType]);
        }

        $other = app(TaxonomyRegistry::class)->bySegment($taxonomy->segment);

        if ($other !== null && $other->key !== $taxonomy->key) {
            return __('/admin/:segment already shows :plural.', ['segment' => $taxonomy->segment, 'plural' => $other->plural]);
        }

        $storedTaxonomy = DB::table(StoredTaxonomies::TABLE)
            ->where('key', '!=', $taxonomy->key)
            ->whereRaw("definition ->> 'segment' = ?", [$taxonomy->segment])
            ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
            ->value('plural');

        if ($storedTaxonomy !== null) {
            return __('/admin/:segment already shows :plural.', ['segment' => $taxonomy->segment, 'plural' => (string) $storedTaxonomy]);
        }

        return null;
    }

    /**
     * What stops a public taxonomy taking its address base: it is empty or
     * badly written, the site's own, or already the first word of other
     * addresses. A taxonomy becoming public has no base to default to.
     */
    private static function baseProblem(TaxonomyDefinition $taxonomy, ?TaxonomyDefinition $replacing): ?string
    {
        $base = $taxonomy->base ?? ($replacing === null ? $taxonomy->key : '');

        if ($base === '') {
            return __('Give the address base: the first part of each :singular’s address.', ['singular' => mb_strtolower($taxonomy->singular)]);
        }

        if (preg_match(self::BASE, $base) !== 1) {
            return __('Use lower-case letters, numbers and hyphens only.');
        }

        if (Paths::taken($base)) {
            return __('Addresses starting /:segment/ are used by the site itself.', ['segment' => $base]);
        }

        $settings = AddressSettings::current();
        $held = [$settings->authorBase, ...array_values($settings->archives)];

        foreach ($settings->bases as $key => $existing) {
            if ($key !== $taxonomy->key) {
                $held[] = $existing;
            }
        }

        foreach ($settings->patterns as $pattern) {
            try {
                $held[] = AddressPattern::parse($pattern)->firstFixedSegment();
            } catch (InvalidArgumentException) {
                // A pattern that doesn't parse holds no address.
            }
        }

        $stored = DB::table(StoredTaxonomies::TABLE)
            ->where('key', '!=', $taxonomy->key)
            ->whereRaw("definition ->> 'base' = ?", [$base])
            ->exists();

        if ($stored || in_array($base, $held, true)) {
            return __('Addresses starting /:base/ are already in use on this site.', ['base' => $base]);
        }

        return null;
    }
}
