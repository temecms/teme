<?php

/**
 * What every change to the site's own types shares (I-411): who may make
 * it, the checks only the stored rows and the items can answer, made under
 * the types' lock, and what follows its commit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Concerns;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Cache\PageTags;
use App\Domain\Support\Security\Operator;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeAccess;
use App\Domain\Types\TypeDefinition;
use App\Domain\Types\TypeOwners;
use App\Domain\Types\TypePermissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

trait ManagesTypes
{
    /**
     * The operator at the server; or a person who holds types.manage, while
     * the host hasn't locked the types.
     *
     * @throws AuthorizationException
     */
    private function authorizeTypes(OperationInput $input): void
    {
        $actorId = $input->actorId();

        if ($actorId === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Only a person who may manage types, or the operator at the server, changes them.');
        }

        $actor = User::query()->find($actorId);

        if ($actor === null || ! $actor->can(TypeAccess::MANAGE)) {
            throw new AuthorizationException('Not permitted to change the types.');
        }

        if (TypeAccess::locked()) {
            throw new AuthorizationException(TypeAccess::LOCKED);
        }
    }

    /**
     * Under the types' lock: another stored row with this key (for a new
     * type) or this admin segment (a type's or a taxonomy's), as rows another change may have just
     * committed say.
     *
     * @return array<string, string>
     */
    private function takenProblems(TypeDefinition $type, bool $new): array
    {
        $problems = [];

        if ($new && DB::table(StoredTypes::TABLE)->where('key', $type->key)->exists()) {
            $problems['key'] = __('There’s already a type with the key :key.', ['key' => $type->key]);
        }

        $other = DB::table(StoredTypes::TABLE)
            ->where('key', '!=', $type->key)
            ->whereRaw("definition ->> 'segment' = ?", [$type->segment])
            ->selectRaw("definition ->> 'plural' AS plural")
            ->value('plural');

        $taxonomy = DB::table(StoredTaxonomies::TABLE)
            ->whereRaw("definition ->> 'segment' = ?", [$type->segment])
            ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
            ->value('plural');

        if ($other !== null) {
            $problems['segment'] = __('/admin/:segment already shows :plural.', ['segment' => $type->segment, 'plural' => (string) $other]);
        } elseif ($taxonomy !== null) {
            $problems['segment'] = __('/admin/:segment already shows :plural.', ['segment' => $type->segment, 'plural' => (string) $taxonomy]);
        }

        return $problems;
    }

    /**
     * Under the types' lock: what the type's items stop a change from doing
     * (I-412): whether it is public can't change while it has any, trashed
     * ones included; it can't become one item only with more than one; a
     * taxonomy can't be taken from it while its items have terms in it.
     *
     * @return array<string, string>
     */
    private function itemProblems(TypeDefinition $next, TypeDefinition $current): array
    {
        $problems = [];

        if ($next->public !== $current->public) {
            // DB::table() sees soft-deleted rows: trashed items count.
            $count = DB::table('contents')->where('type', $current->key)->count();

            if ($count > 0) {
                $problems['public'] = __(':plural have items (:count, trashed ones included), so whether they’re public can’t change.', ['plural' => $current->plural, 'count' => (string) $count]);
            }
        }

        // A type with one item only holds one, trashed ones counted (I-415).
        if ($next->single && ! $current->single) {
            $count = DB::table('contents')->where('type', $current->key)->count();

            if ($count > 1) {
                $problems['single'] = __(':plural have :count items, so they can’t become one item only.', ['plural' => $current->plural, 'count' => (string) $count]);
            }
        }

        foreach (array_diff($current->taxonomies, $next->taxonomies) as $taxonomy) {
            $used = DB::table('content_terms')
                ->join('contents', 'contents.id', '=', 'content_terms.content_id')
                ->join('terms', 'terms.id', '=', 'content_terms.term_id')
                ->where('contents.type', $current->key)
                ->where('terms.taxonomy', $taxonomy)
                ->exists();

            if ($used) {
                $taxonomies = app(TaxonomyRegistry::class);
                $name = $taxonomies->has($taxonomy) ? $taxonomies->get($taxonomy)->plural : $taxonomy;
                $problems['taxonomies'] = __(':plural still use :taxonomy: take those terms off them first.', ['plural' => $current->plural, 'taxonomy' => $name]);

                break;
            }
        }

        return $problems;
    }

    /**
     * Under the types' lock: a type gaining its own permissions can't take
     * a name that already exists, so removing them later never removes
     * another's (I-413).
     *
     * @return array<string, string>
     */
    private function permissionProblems(TypeDefinition $next, ?TypeDefinition $current): array
    {
        if ($next->permissions !== TypeDefinition::OWN || $current?->permissions === TypeDefinition::OWN) {
            return [];
        }

        $existing = TypePermissions::existing($next->key);

        if ($existing === []) {
            return [];
        }

        return ['permissions' => __('A permission named :name already exists, so :plural can’t have permissions of their own.', ['name' => $existing[0], 'plural' => $next->plural])];
    }

    /**
     * Under the types' lock, once the row is written: makes the type's own
     * permissions when it gains them, removes them when it gives them up.
     * A file's grants replace the starting set for the roles they name.
     *
     * @param  array<string, list<string>>  $grants
     */
    private function applyPermissions(TypeDefinition $next, ?TypeDefinition $current, array $grants = []): void
    {
        $had = $current?->permissions === TypeDefinition::OWN;
        $has = $next->permissions === TypeDefinition::OWN;

        if ($has && ! $had) {
            TypePermissions::make($next->key, $grants);
        } elseif ($had && ! $has) {
            TypePermissions::remove($next->key);
        }
    }

    /**
     * Under the types' lock: refuses a change to a type, taxonomy or field a
     * plugin brought, which only that plugin's updates change
     * (docs/architecture/plugins.md, *Content types from a plugin*).
     *
     * @param  'types'|'taxonomies'|'fields'  $kind
     *
     * @throws ValidationException
     */
    private function refuseIfPlugins(string $kind, string $key): void
    {
        $plugin = TypeOwners::owner($kind, $key);

        if ($plugin !== null) {
            throw ValidationException::withMessages(['key' => __('This comes from the plugin :plugin, so only its updates change it.', ['plugin' => TypeOwners::label($plugin)])]);
        }
    }

    /**
     * @param  array<string, string>  $problems
     *
     * @throws ValidationException
     */
    private function refuse(array $problems, string $prefix = ''): void
    {
        if ($problems === []) {
            return;
        }

        throw ValidationException::withMessages(array_combine(
            array_map(fn (string $field): string => $prefix.$field, array_keys($problems)),
            array_values($problems),
        ));
    }

    /**
     * Tells the page cache every page may show the change: lists, addresses,
     * titles and feeds all read the types (I-263).
     */
    private function purgeArchive(string $key): void
    {
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SETTINGS, 'types', [PageTags::listOf($key)]));
    }

    /**
     * The archive page's own heading and intro changed and nothing else did.
     */
    private function onlyArchiveTextChanged(TypeDefinition $next, TypeDefinition $current): bool
    {
        $without = static fn (TypeDefinition $type): array => array_diff_key($type->toArray(), ['archive_heading' => 1, 'archive_intro' => 1]);

        return $without($next) === $without($current);
    }

    private function purgeEverything(): void
    {
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));
    }

    /**
     * After commit, in this order (I-3296): Spatie's cached permissions,
     * which a reader may have cached again without this change's; the cache
     * store's copy of the types, which other processes read; this process's
     * registry; this request's address settings, read before the change. A
     * cache store that refuses is reported, never thrown: the change has
     * committed, and the page cache must still be emptied.
     */
    private function typesChanged(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable $refused) {
            report($refused);
        }

        try {
            app(StoredTypes::class)->remember();
        } catch (Throwable $refused) {
            report($refused);
        }

        app(ContentTypeRegistry::class)->refreshStored();
        app()->forgetInstance(AddressSettings::class);
    }
}
