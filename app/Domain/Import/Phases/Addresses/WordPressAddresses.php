<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Addresses;

use App\Domain\Addresses\AddressPattern;
use App\Domain\Addresses\Paths;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Teme's address settings for a WordPress site's (I-393): its permalink
 * structure as a pattern, tag by tag, its category and tag bases, and an
 * empty category base where the site strips it. What Teme can't write takes
 * the nearest it can, and says so; each old address then answers as an old
 * address of what it led to.
 */
final class WordPressAddresses
{
    /** WordPress's post type for posts. */
    public const string POSTS = 'post';

    /** WordPress's post type for pages. */
    public const string PAGES = 'page';

    /** WordPress's taxonomy for categories. */
    public const string CATEGORIES = 'category';

    /** WordPress's taxonomy for tags. */
    public const string TAGS = 'post_tag';

    /** The base WordPress gives categories when the site names none. */
    private const string CATEGORY_BASE = 'category';

    /** The base WordPress gives tags when the site names none. */
    private const string TAG_BASE = 'tag';

    /** The structure tags Teme has a token for. */
    private const array TOKENS = [
        '%year%' => '{year}',
        '%monthnum%' => '{month}',
        '%day%' => '{day}',
        '%postname%' => '{slug}',
        '%category%' => '{category}',
        '%author%' => '{author}',
    ];

    /**
     * Teme's type for a WordPress post type, by the WordPress name the
     * registry gives it, or null for one Teme hasn't.
     */
    public static function temeType(string $wordpress): ?string
    {
        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($wordpress !== '' && $type->wordpress === $wordpress) {
                return $type->key;
            }
        }

        return null;
    }

    /**
     * Teme's taxonomy for a WordPress taxonomy, by the WordPress name the
     * registry gives it, or null for one Teme hasn't.
     */
    public static function temeTaxonomy(string $wordpress): ?Taxonomy
    {
        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            if ($wordpress !== '' && $taxonomy->wordpress === $wordpress) {
                return $taxonomy;
            }
        }

        return null;
    }

    /**
     * The pattern for posts from WordPress's permalink structure, and
     * whether every post keeps its address: each part one tag Teme has, or
     * plain text; any other part left out; a pattern left without {slug}
     * ending with it. One Teme still can't take is /{slug}/.
     *
     * @return array{pattern: string, exact: bool}
     */
    public static function pattern(string $structure): array
    {
        $structure = trim($structure);
        $slash = str_ends_with($structure, '/');
        $exact = true;

        if (self::indexed($structure)) {
            $structure = substr($structure, strlen('/index.php'));
            $exact = false;
        }

        $segments = [];

        foreach (explode('/', $structure) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (isset(self::TOKENS[$segment])) {
                $segments[] = self::TOKENS[$segment];
            } elseif (preg_match('/^[A-Za-z0-9_-]+$/', $segment) === 1) {
                $segments[] = strtolower($segment);
                $exact = $exact && $segment === strtolower($segment);
            } else {
                $exact = false;

                // A part that mixes the name with other tags or text keeps the name alone.
                if (str_contains($segment, '%postname%')) {
                    $segments[] = '{slug}';
                }
            }
        }

        if (! in_array('{slug}', $segments, true)) {
            $segments[] = '{slug}';
            $exact = false;
        }

        $pattern = '/'.implode('/', $segments).($slash ? '/' : '');

        try {
            $parsed = AddressPattern::parse($pattern);
        } catch (InvalidArgumentException) {
            $parsed = null;
        }

        if ($parsed === null || Paths::taken($parsed->firstFixedSegment())) {
            return ['pattern' => $slash ? '/{slug}/' : '/{slug}', 'exact' => false];
        }

        return ['pattern' => $pattern, 'exact' => $exact];
    }

    /**
     * The structure before its first tag, as WordPress reads it: `/` for
     * /%postname%/, `/blog/` for /blog/%postname%/.
     */
    public static function front(string $structure): string
    {
        $at = strpos($structure, '%');

        return $at === false ? '/' : substr($structure, 0, $at);
    }

    /**
     * Whether categories' addresses leave their base out, as Yoast SEO's and
     * Rank Math's option does, read from one category's own address: true
     * when it doesn't hold the base WordPress would put there.
     */
    public static function stripsCategoryBase(?string $link, string $structure, string $categoryBase): bool
    {
        $path = $link === null ? null : parse_url($link, PHP_URL_PATH);

        if (! is_string($path) || trim($path, '/') === '') {
            return false;
        }

        $base = trim($categoryBase, '/');
        $expected = trim($base === '' ? self::front($structure).self::CATEGORY_BASE : $base, '/');

        return ! str_contains('/'.trim($path, '/').'/', '/'.$expected.'/');
    }

    /**
     * Teme's address settings for the site's: the posts' and pages'
     * patterns where Teme has those types, the author base, and the
     * category and tag bases where it has those taxonomies, keyed as the
     * addresses group's settings; and one warning for each kind of address
     * that changes.
     *
     * @return array{values: array<string, string>, messages: list<ImportMessage>}
     */
    public static function plan(string $structure, string $categoryBase, string $tagBase, bool $stripped, ?string $postType, ?string $pageType, ?string $categoryTaxonomy, ?string $tagTaxonomy): array
    {
        $structure = trim($structure);
        $front = self::front($structure);
        $indexed = self::indexed($structure);
        $posts = self::pattern($structure);
        // Pages keep their parents' slugs, as WordPress gives them (I-3363).
        $pages = str_ends_with($structure, '/') ? '/{parent}/{slug}/' : '/{parent}/{slug}';
        $values = [];
        $messages = [];

        if ($postType !== null) {
            $values['pattern_'.$postType] = $posts['pattern'];
        }

        if ($pageType !== null) {
            $values['pattern_'.$pageType] = $pages;
        }

        $values['author_base'] = 'author';

        if (! $posts['exact']) {
            // Cut short in words: a message holds at most 1,000 characters.
            $was = Str::limit($structure, 200);
            $messages[] = self::changed('posts', "Posts’ addresses on the old site were shaped {$was}, which Teme can’t write, so posts here are at {$posts['pattern']}. Each post’s old address answers with a permanent redirect to its new one.", $was, $posts['pattern']);
        }

        if ($indexed) {
            $messages[] = self::changed('pages', "Pages’ addresses on the old site started /index.php/, which Teme doesn’t use, so pages here are at {$pages}. Each page’s old address answers with a permanent redirect to its new one.", '/index.php/', $pages);
        }

        if ($categoryTaxonomy !== null) {
            $category = $stripped
                ? ['base' => '', 'exact' => ! $indexed && ($front === '/' || trim($categoryBase, '/') !== '')]
                : self::base($categoryBase, self::CATEGORY_BASE, $front);
            $values['base_'.$categoryTaxonomy] = $category['base'];

            if (! $category['exact']) {
                $was = self::prefix($categoryBase, $stripped ? '' : self::CATEGORY_BASE, $front);
                $now = $category['base'] === '' ? '/' : '/'.$category['base'].'/';
                $messages[] = self::changed('categories', "Categories’ addresses on the old site started {$was}, which Teme can’t write, so here they start {$now}. Each category’s old address answers with a permanent redirect to its new one.", $was, $now);
            }
        }

        if ($tagTaxonomy !== null) {
            $tag = self::base($tagBase, self::TAG_BASE, $front);
            $values['base_'.$tagTaxonomy] = $tag['base'];

            if (! $tag['exact']) {
                $was = self::prefix($tagBase, self::TAG_BASE, $front);
                $now = '/'.$tag['base'].'/';
                $messages[] = self::changed('tags', "Tags’ addresses on the old site started {$was}, which Teme can’t write, so here they start {$now}. Each tag’s old address answers with a permanent redirect to its new one.", $was, $now);
            }
        }

        if ($front !== '/') {
            $was = Str::limit($front, 200).'author/';
            $messages[] = self::changed('authors', "Authors’ pages on the old site started {$was}, so here they start /author/. Each author page’s old address answers with a permanent redirect to its new one.", $was, '/author/');
        }

        return ['values' => $values, 'messages' => $messages];
    }

    /**
     * Whether the structure's addresses start /index.php/, WordPress's own
     * for a server that can't rewrite them.
     */
    private static function indexed(string $structure): bool
    {
        return preg_match('#^/index\.php(?:/|$)#i', $structure) === 1;
    }

    /**
     * Teme's base for a taxonomy whose WordPress base is $base, or $default
     * after the structure's front when empty: the same, lower-cased, where
     * Teme can write it, else $default; and whether every address keeps its
     * shape.
     *
     * @return array{base: string, exact: bool}
     */
    private static function base(string $base, string $default, string $front): array
    {
        $trimmed = trim($base, '/');

        if ($trimmed === '') {
            return ['base' => $default, 'exact' => $front === '/'];
        }

        $lower = strtolower($trimmed);

        if (preg_match('/^[a-z0-9-]+$/', $lower) !== 1 || Paths::taken($lower)) {
            return ['base' => $default, 'exact' => false];
        }

        // A base the site named sits at the root, unless its addresses start /index.php/ (I-393).
        return ['base' => $lower, 'exact' => $lower === $trimmed && ! self::indexed($front)];
    }

    /**
     * Where a taxonomy's addresses started on the old site, as people saw
     * them: its base, or the structure's front and WordPress's default.
     */
    private static function prefix(string $base, string $default, string $front): string
    {
        $trimmed = trim($base, '/');

        if ($trimmed !== '') {
            return '/'.Str::limit($trimmed, 200).'/';
        }

        return Str::limit($default === '' ? $front : $front.$default.'/', 200);
    }

    private static function changed(string $kind, string $words, string $was, string $now): ImportMessage
    {
        return new ImportMessage('address.changed', $words, ['kind' => $kind, 'was' => $was, 'now' => $now]);
    }
}
