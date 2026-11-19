<?php

/**
 * Which public address every item, term and author should have now, from the
 * address settings (I-393): stored without slashes, lower-cased, and '' for
 * the home page. No list of types or taxonomies lives here; the registries'
 * `public` flags and the settings decide.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Languages\SiteLanguages;
use App\Domain\People\Credits;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final class Addresses
{
    /**
     * The item's address: its type's pattern for a published, live item of a
     * public type, public or protected; '' for the page shown as the home page;
     * null for anything else, including a private item and anything in the
     * trash (which keeps its status).
     *
     * @param  string|null  $parentPath  the {parent} value to use, or null to read it from the item's own parent (I-3363)
     * @param  string|null  $authorSlug  the {author} value to use, or null to read the item's first credit as stored (I-3366)
     */
    public static function forContent(Content $content, ?string $parentPath = null, ?string $authorSlug = null): ?string
    {
        if ($content->trashed() || $content->status !== 'published' || $content->visibility === Visibility::Private) {
            return null;
        }

        return self::wouldHave($content, $content->slug, self::publishedTerms($content->id), $authorSlug, $content->published_at, $content->fields[BuiltInFields::PRIMARY_CATEGORY] ?? null, $parentPath);
    }

    /**
     * The address the item would have once published with these details, so
     * an Operation can check it for a clash before it saves anything. Null
     * for a type with no public address.
     *
     * @param  list<string>  $terms  term IDs in their order, as a revision holds them
     * @param  string|null  $authorSlug  the {author} value to use, the slug of the item's first credit, or null to read it as stored (I-3366)
     * @param  mixed  $primaryCategory  the primary category field's value on the revision the address is for (I-869)
     * @param  string|null  $parentPath  the {parent} value to use, or null to read it from the item's own parent (I-3363)
     */
    public static function wouldHave(Content $content, string $slug, array $terms, ?string $authorSlug, CarbonInterface|string|null $publishedAt, mixed $primaryCategory = null, ?string $parentPath = null): ?string
    {
        $settings = AddressSettings::current();
        // Another language's address is its prefix, then the address the pattern gives (I-3360).
        $language = self::stored($content, 'language');

        if ($settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId !== '' && $settings->frontPageId === $content->id) {
            return self::prefixed($language, '');
        }

        // The home page's version in another language is that language's home.
        if ($language !== null && $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId !== '' && self::isFrontVersion($content, $settings->frontPageId)) {
            return self::prefixed($language, '');
        }

        if (! $settings->hasPattern($content->type)) {
            return null;
        }

        $pattern = $settings->pattern($content->type);
        $tokens = $pattern->tokens();
        $values = ['slug' => $slug, 'id' => (string) $content->id];

        if (array_intersect($tokens, ['year', 'month', 'day']) !== []) {
            // The site's clock, as the admin shows dates, never the server's.
            $date = CarbonImmutable::parse($publishedAt ?? now())
                ->setTimezone(app(SettingsReader::class)->string('general', 'timezone'));
            $values += ['year' => $date->format('Y'), 'month' => $date->format('m'), 'day' => $date->format('d')];
        }

        if (in_array(AddressPattern::CATEGORY, $tokens, true)) {
            $values[AddressPattern::CATEGORY] = self::categoryPath($content->type, $terms, $primaryCategory);
        }

        // Read only for a pattern that holds it, so a post costs no statement more.
        if (in_array(AddressPattern::PARENT, $tokens, true)) {
            $values[AddressPattern::PARENT] = $parentPath ?? app(ContentTree::class)->parentPath($content->parent_id);
        }

        // The first credit's (I-3366); an item with no owner and no bylines has none, as before.
        if (in_array('author', $tokens, true)) {
            $authorSlug ??= app(Credits::class)->firstSlug((string) $content->id);

            if ($authorSlug !== null) {
                $values['author'] = $authorSlug;
            }
        }

        return self::prefixed($language, $pattern->render($values));
    }

    /**
     * The {author} value an item owned by this account would take while it
     * has no bylines: the account's slug, read only when the item's type's
     * pattern holds {author}; null otherwise, or with no owner.
     */
    public static function ownerSlug(Content $content, ?int $ownerId): ?string
    {
        $settings = AddressSettings::current();

        if ($ownerId === null || ! $settings->hasPattern($content->type) || ! in_array('author', $settings->pattern($content->type)->tokens(), true)) {
            return null;
        }

        $slug = DB::table('users')->where('id', $ownerId)->value('slug');

        return $slug === null ? null : (string) $slug;
    }

    /**
     * The term's address: its taxonomy's base, its ancestors' slugs, then its
     * own; just the slugs when the base is empty. Null for a taxonomy that
     * isn't public.
     */
    public static function forTerm(Term $term): ?string
    {
        $settings = AddressSettings::current();

        if (! $settings->hasBase($term->taxonomy)) {
            return null;
        }

        $address = mb_strtolower(implode('/', array_filter([$settings->base($term->taxonomy), self::termPath($term->id)], fn (string $part): bool => $part !== '')));

        // Another language's term carries its prefix, as its items do (I-3360).
        $language = array_key_exists('language', $term->getAttributes())
            ? $term->getAttributes()['language']
            : DB::table('terms')->where('id', $term->id)->value('language');

        return self::prefixed(is_string($language) ? $language : null, $address);
    }

    /**
     * The author's address: the author base, then their slug.
     */
    public static function forUser(User $user): string
    {
        return mb_strtolower(AddressSettings::current()->authorBase.'/'.$user->slug);
    }

    /**
     * A person with no account's address: the author base, then their slug,
     * as an account's is (I-3366).
     */
    public static function forPerson(string $slug): string
    {
        return mb_strtolower(AddressSettings::current()->authorBase.'/'.$slug);
    }

    /**
     * The target's live address as an absolute URL, with the trailing slash
     * its pattern gives, or null when it has none. Built as
     * `rtrim(url('/'), '/').$path`: url($path) would trim the slash, and every
     * internal link would then answer with a 301.
     */
    public static function urlFor(Content|Term|User $target): ?string
    {
        $path = self::relativeUrl($target);

        return $path === null ? null : rtrim(url('/'), '/').$path;
    }

    /**
     * The target's live address as a site-relative URL, such as `/about/`,
     * or null when it has none.
     */
    public static function relativeUrl(Content|Term|User $target): ?string
    {
        [$type, $id] = match (true) {
            $target instanceof Content => ['content', (string) $target->id],
            $target instanceof Term => ['term', (string) $target->id],
            default => ['user', (string) $target->id],
        };

        $path = Paths::current($type, $id);

        return $path === null ? null : self::toUrl($path, $target instanceof Content ? $target->type : null);
    }

    /**
     * A stored address as a site-relative URL: a leading slash, and the
     * trailing slash the pattern gives. Terms, authors and listings follow
     * the first public type with an archive's pattern.
     */
    public static function toUrl(string $path, ?string $type = null): string
    {
        if ($path === '') {
            return '/';
        }

        return '/'.$path.(self::trailingSlash($type) ? '/' : '');
    }

    /**
     * Whether addresses of this type end in a slash; for a term, an author or
     * a listing (null), the first public type with an archive's pattern decides.
     */
    public static function trailingSlash(?string $type = null): bool
    {
        $settings = AddressSettings::current();
        $type ??= ListingTypes::first();

        return $type !== null && $settings->hasPattern($type) && $settings->pattern($type)->trailingSlash();
    }

    /**
     * An address in a stored language: the default's as it is, another's
     * after that language's prefix, the prefix alone for its home.
     */
    private static function prefixed(?string $language, string $address): string
    {
        if ($language === null) {
            return $address;
        }

        $prefix = app(SiteLanguages::class)->prefix($language);

        return $address === '' ? $prefix : $prefix.'/'.$address;
    }

    /**
     * One of the item's language columns: from the attributes the model
     * holds, else one read of its row by ID, as a stub or a narrow select
     * may lack it.
     */
    private static function stored(Content $content, string $column): ?string
    {
        $value = array_key_exists($column, $content->getAttributes())
            ? $content->getAttributes()[$column]
            : DB::table('contents')->where('id', $content->id)->value($column);

        return is_string($value) ? $value : null;
    }

    /**
     * Whether the item is a version of the page shown as the home page: in
     * its translation group, and not that page itself.
     */
    private static function isFrontVersion(Content $content, string $frontPageId): bool
    {
        $group = self::stored($content, 'translation_group');

        return $group !== null && $content->id !== $frontPageId
            && DB::table('contents')->where('id', $frontPageId)->where('translation_group', $group)->exists();
    }

    /**
     * The term IDs on the item's published revision, in their order.
     *
     * @return list<string>
     */
    private static function publishedTerms(string $contentId): array
    {
        /** @var list<string> */
        return DB::table('content_terms')
            ->where('content_id', $contentId)
            ->where('scope', 'published')
            ->orderBy('position')
            ->pluck('term_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * `{category}`: the path of the item's primary category (I-869), its
     * parents before it, or '' when it has none.
     *
     * @param  list<string>  $terms
     */
    private static function categoryPath(string $type, array $terms, mixed $primary): string
    {
        $hierarchical = PrimaryCategory::taxonomyFor($type);

        if ($hierarchical === null || $terms === []) {
            return '';
        }

        $inTaxonomy = DB::table('terms')->whereIn('id', $terms)->where('taxonomy', $hierarchical)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $chosen = PrimaryCategory::pick(array_values(array_filter($terms, fn (string $id): bool => in_array($id, $inTaxonomy, true))), $primary);

        return $chosen === null ? '' : self::termPath($chosen);
    }

    /**
     * The term's slug after its ancestors', such as `news/local`.
     */
    private static function termPath(string $termId): string
    {
        $slugs = DB::select(<<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT id, parent_id, slug, 0 AS depth FROM terms WHERE id = ?
                UNION ALL
                SELECT t.id, t.parent_id, t.slug, chain.depth + 1 FROM terms t JOIN chain ON t.id = chain.parent_id
            )
            SELECT slug FROM chain ORDER BY depth DESC
            SQL, [$termId]);

        return implode('/', array_map(fn (object $row): string => (string) $row->slug, $slugs));
    }
}
