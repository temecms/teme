<?php

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Whether new address settings would put two things at one address, found
 * with a few set queries rather than by rendering every item. The pairs it
 * checks come from the registries, so a type registered later is checked
 * too.
 */
final class AddressConflicts
{
    /** The most clashes one refusal lists. */
    private const int MOST = 20;

    private const string ARCHIVE = 'archive page';

    /**
     * Up to twenty clashes under these settings, each "X and Y would both be
     * at /address/"; empty when there are none.
     *
     * @param  list<string>  $moving  the types whose archive page this change moves; only they are checked
     *
     * @return list<string>
     */
    public static function check(AddressSettings $next, array $moving = []): array
    {
        $types = app(ContentTypeRegistry::class);
        $taxonomies = app(TaxonomyRegistry::class);
        $clashes = [];

        $patternKeys = array_keys($next->patterns);
        $baseKeys = array_keys($next->bases);

        // Two public types with the same pattern: their items with the same slug.
        foreach ($patternKeys as $i => $a) {
            foreach (array_slice($patternKeys, $i + 1) as $b) {
                if (self::flat($next->patterns[$a]) === self::flat($next->patterns[$b])) {
                    $rows = DB::table('contents as p')
                        ->join('contents as q', 'p.slug', '=', 'q.slug')
                        ->where('p.type', $a)->where('q.type', $b)
                        ->when(self::nests($next->patterns[$a]), fn ($query) => $query->whereNull('p.parent_id'))
                        ->when(self::nests($next->patterns[$b]), fn ($query) => $query->whereNull('q.parent_id'))
                        ->where('p.status', 'published')->where('q.status', 'published')
                        ->whereNull('p.deleted_at')->whereNull('q.deleted_at')
                        ->limit(self::MOST)
                        ->get(['p.title as first', 'q.title as second', 'p.slug']);

                    foreach ($rows as $row) {
                        $clashes[] = self::line(self::noun($types->get($a)->singular), (string) $row->first, self::noun($types->get($b)->singular), (string) $row->second, (string) $row->slug);
                    }
                }
            }
        }

        // A taxonomy at the root: its top-level terms against every type
        // whose items also sit at the root, by slug alone.
        foreach ($baseKeys as $taxonomy) {
            if ($next->bases[$taxonomy] !== '') {
                continue;
            }

            foreach ($patternKeys as $type) {
                if (self::flat($next->patterns[$type]) !== '{slug}') {
                    continue;
                }

                $rows = DB::table('terms')
                    ->join('contents', 'contents.slug', '=', 'terms.slug')
                    ->where('terms.taxonomy', $taxonomy)->whereNull('terms.parent_id')
                    ->where('contents.type', $type)
                    ->when(self::nests($next->patterns[$type]), fn ($query) => $query->whereNull('contents.parent_id'))->where('contents.status', 'published')->whereNull('contents.deleted_at')
                    ->limit(self::MOST)
                    ->get(['terms.name', 'contents.title', 'terms.slug']);

                foreach ($rows as $row) {
                    $clashes[] = self::line(self::noun($taxonomies->get($taxonomy)->singular), (string) $row->name, self::noun($types->get($type)->singular), (string) $row->title, (string) $row->slug);
                }
            }
        }

        // Two taxonomies with the same base: their top-level terms by slug.
        foreach ($baseKeys as $i => $a) {
            foreach (array_slice($baseKeys, $i + 1) as $b) {
                if ($next->bases[$a] !== $next->bases[$b]) {
                    continue;
                }

                $rows = DB::table('terms as p')
                    ->join('terms as q', 'p.slug', '=', 'q.slug')
                    ->where('p.taxonomy', $a)->where('q.taxonomy', $b)
                    ->whereNull('p.parent_id')->whereNull('q.parent_id')
                    ->limit(self::MOST)
                    ->get(['p.name as first', 'q.name as second', 'p.slug']);

                $base = $next->bases[$a];

                foreach ($rows as $row) {
                    $clashes[] = self::line(self::noun($taxonomies->get($a)->singular), (string) $row->first, self::noun($taxonomies->get($b)->singular), (string) $row->second, ($base === '' ? '' : $base.'/').$row->slug);
                }
            }
        }

        // A type's archive page this change moves (I-3304): at another's
        // address; where something lives now; where these settings would put
        // an item or a top-level term at the root.
        $archiveKeys = array_keys($next->archives);
        $current = AddressSettings::current();

        foreach ($archiveKeys as $i => $a) {
            foreach (array_slice($archiveKeys, $i + 1) as $b) {
                if ($next->archives[$a] === $next->archives[$b] && (in_array($a, $moving, true) || in_array($b, $moving, true))) {
                    $clashes[] = self::line(self::ARCHIVE, $types->get($a)->plural, self::ARCHIVE, $types->get($b)->plural, $next->archives[$a]);
                }
            }
        }

        foreach ($moving as $type) {
            $address = $next->archives[$type];
            $plural = $types->get($type)->plural;
            $holder = DB::table('paths')->where('path', $address)->where('is_current', true)->first(['target_type', 'target_id']);

            if ($holder !== null) {
                $clashes[] = __('The archive page ":first" and :holder would both be at :address.', [
                    'first' => $plural,
                    'holder' => Paths::describe((string) $holder->target_type, (string) $holder->target_id),
                    'address' => '/'.$address.'/',
                ]);
            }

            // Only a pattern this change sets: an item under one in force
            // already has its live row, which the check above reads, and an
            // item with none there (private, or the home page) holds nothing.
            foreach ($patternKeys as $other) {
                if (self::flat($next->patterns[$other]) !== '{slug}' || $next->patterns[$other] === ($current->patterns[$other] ?? null)) {
                    continue;
                }

                $title = DB::table('contents')->where('type', $other)->where('slug', $address)
                    ->when(self::nests($next->patterns[$other]), fn ($query) => $query->whereNull('parent_id'))
                    ->where('status', 'published')->whereNull('deleted_at')->value('title');

                if ($title !== null) {
                    $clashes[] = self::line(self::ARCHIVE, $plural, self::noun($types->get($other)->singular), (string) $title, $address);
                }
            }

            foreach ($baseKeys as $taxonomy) {
                if ($next->bases[$taxonomy] !== '') {
                    continue;
                }

                $name = DB::table('terms')->where('taxonomy', $taxonomy)->whereNull('parent_id')->where('slug', $address)->value('name');

                if ($name !== null) {
                    $clashes[] = self::line(self::ARCHIVE, $plural, self::noun($taxonomies->get($taxonomy)->singular), (string) $name, $address);
                }
            }
        }

        // The page that lists posts cannot also be the home page.
        if ($next->frontShows === AddressSettings::FRONT_PAGE && $next->postsPageId !== '' && $next->postsPageId === $next->frontPageId) {
            $clashes[] = __('The home page and the posts page must be different pages.');
        }

        return array_slice(array_values(array_unique($clashes)), 0, self::MOST);
    }

    private static function line(string $firstNoun, string $first, string $secondNoun, string $second, string $address): string
    {
        return __('The :first_noun ":first" and the :second_noun ":second" would both be at :address.', [
            'first_noun' => $firstNoun,
            'first' => $first,
            'second_noun' => $secondNoun,
            'second' => $second,
            'address' => '/'.$address.'/',
        ]);
    }

    /**
     * The pattern without its slashes and its {parent}, which a top-level
     * item fills with nothing (I-3363).
     */
    private static function flat(string $pattern): string
    {
        return trim(str_replace('{'.AddressPattern::PARENT.'}/', '', $pattern), '/');
    }

    /**
     * Whether the pattern holds {parent}: only its type's top-level items
     * are compared, a nested item's clash left to the recompute's own refusal.
     */
    private static function nests(string $pattern): bool
    {
        return str_contains($pattern, '{'.AddressPattern::PARENT.'}');
    }

    private static function noun(string $singular): string
    {
        return mb_strtolower($singular);
    }
}
