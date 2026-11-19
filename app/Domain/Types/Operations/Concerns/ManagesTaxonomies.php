<?php

/**
 * What every change to the site's own taxonomies shares beyond a type's
 * (I-414): the checks only the stored rows and the terms can answer, made
 * under the types' lock. Used beside ManagesTypes, which gives who may make
 * a change and what follows its commit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Concerns;

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyDefinition;
use Illuminate\Support\Facades\DB;

trait ManagesTaxonomies
{
    /**
     * Under the types' lock: another stored row with this key (for a new
     * taxonomy), this admin segment, or (for a new public one, or one
     * becoming public) this base, as rows another change may have just
     * committed say.
     *
     * @return array<string, string>
     */
    private function taxonomyTakenProblems(TaxonomyDefinition $next, bool $new, bool $newBase = false): array
    {
        $problems = [];

        if ($new && DB::table(StoredTaxonomies::TABLE)->where('key', $next->key)->exists()) {
            $problems['key'] = __('There’s already a taxonomy with the key :key.', ['key' => $next->key]);
        }

        $segment = DB::table(StoredTaxonomies::TABLE)
            ->where('key', '!=', $next->key)
            ->whereRaw("definition ->> 'segment' = ?", [$next->segment])
            ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
            ->value('plural')
            ?? DB::table(StoredTypes::TABLE)
                ->whereRaw("definition ->> 'segment' = ?", [$next->segment])
                ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
                ->value('plural');

        if ($segment !== null) {
            $problems['segment'] = __('/admin/:segment already shows :plural.', ['segment' => $next->segment, 'plural' => (string) $segment]);
        }

        if (($new || $newBase) && $next->public && $next->base !== null) {
            $held = DB::table(StoredTaxonomies::TABLE)
                ->where('key', '!=', $next->key)
                ->whereRaw("definition ->> 'base' = ?", [$next->base])
                ->exists();

            if ($held) {
                $problems['base'] = __('Addresses starting /:base/ are already in use on this site.', ['base' => $next->base]);
            }
        }

        return $problems;
    }

    /**
     * Under the types' lock: what the taxonomy's terms stop a change from
     * doing (I-3319): nested can't turn flat while any term sits under
     * another, and whether the terms have public pages can't change while
     * there are any.
     *
     * @return array<string, string>
     */
    private function termProblems(TaxonomyDefinition $next, TaxonomyDefinition $current): array
    {
        $problems = [];

        if ($current->hierarchical && ! $next->hierarchical) {
            $under = DB::table('terms')->where('taxonomy', $current->key)->whereNotNull('parent_id')->count();

            if ($under > 0) {
                $problems['hierarchical'] = $under === 1
                    ? __('1 :singular sits under others, so :Plural can’t become flat. Move it to the top first.', ['singular' => mb_strtolower($current->singular), 'Plural' => $current->plural])
                    : __(':count :lowered sit under others, so :Plural can’t become flat. Move them to the top first.', ['count' => (string) $under, 'lowered' => mb_strtolower($current->plural), 'Plural' => $current->plural]);
            }
        }

        if ($next->public !== $current->public) {
            $count = DB::table('terms')->where('taxonomy', $current->key)->count();

            if ($count > 0) {
                $problems['public'] = __(':Plural have terms (:count), so whether they have public pages can’t change.', ['Plural' => $current->plural, 'count' => (string) $count]);
            }
        }

        return $problems;
    }

    /**
     * Under the types' lock: a type can't be taken off while any of its
     * items, trashed ones included, has one of the taxonomy's terms. Names
     * every type that blocks it.
     *
     * @param  list<string>  $typesTakenOff
     *
     * @return array<string, string>
     */
    private function takenOffProblems(TaxonomyDefinition $current, array $typesTakenOff): array
    {
        $types = app(ContentTypeRegistry::class);
        $blocking = [];

        foreach ($types->all() as $type) {
            if (! in_array($type->key, $typesTakenOff, true)) {
                continue;
            }

            // DB::table() sees soft-deleted items: trashed ones count.
            $used = DB::table('content_terms')
                ->join('contents', 'contents.id', '=', 'content_terms.content_id')
                ->join('terms', 'terms.id', '=', 'content_terms.term_id')
                ->where('contents.type', $type->key)
                ->where('terms.taxonomy', $current->key)
                ->exists();

            if ($used) {
                $blocking[] = $type->plural;
            }
        }

        if ($blocking === []) {
            return [];
        }

        $names = count($blocking) === 1
            ? $blocking[0]
            : implode(', ', array_slice($blocking, 0, -1)).' '.__('and').' '.$blocking[count($blocking) - 1];
        $plural = mb_strtolower($current->plural);

        $message = match (count($blocking)) {
            1 => __(':types has items with :plural chosen, so it can’t be taken off. Remove the :plural from its items first.', ['types' => $names, 'plural' => $plural]),
            2 => __(':types have items with :plural chosen, so neither can be taken off. Remove the :plural from their items first.', ['types' => $names, 'plural' => $plural]),
            default => __(':types have items with :plural chosen, so none of them can be taken off. Remove the :plural from their items first.', ['types' => $names, 'plural' => $plural]),
        };

        return ['types' => $message];
    }

    /**
     * Under the types' lock: the stored type rows that name this taxonomy,
     * oldest first.
     *
     * @return list<string>
     */
    private function storedTypesNaming(string $key): array
    {
        return DB::table(StoredTypes::TABLE)
            ->whereRaw("(definition -> 'taxonomies') @> jsonb_build_array(?::text)", [$key])
            ->orderBy('created_at')
            ->orderBy('key')
            ->pluck('key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all();
    }
}
