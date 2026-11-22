<?php

/**
 * The old site's addresses as an export file shows them: each post's and
 * page's own, its terms' and authors' from the bases chosen on the plan page,
 * and the address structure its posts' addresses follow.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use App\Domain\Import\Phases\Addresses\WordPressAddresses;
use Illuminate\Support\Facades\DB;

/**
 * Writes a file's `urls` rows when a run of it starts, in the companion's
 * `urls` shape, and reads its posts' address structure. An address a file
 * can't give, such as an author's under another base, is left out.
 */
final class FileAddresses
{
    /** The base WordPress gives categories when the site names none. */
    public const string CATEGORY_BASE = 'category';

    /** The base WordPress gives tags when the site names none. */
    public const string TAG_BASE = 'tag';

    /** The kinds of address a file lists; the home page is answered whole. */
    public const array KINDS = ['post', 'term', 'author', 'attachment_page', 'attachment_file'];

    /** The types that never have an address of their own. */
    private const array UNADDRESSED = ['wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'nav_menu_item', 'attachment'];

    /**
     * WordPress's own address structures, from Settings › Permalinks, tried
     * in turn: the first that every published post's address follows is
     * taken as the old site's.
     */
    private const array STRUCTURES = [
        '/%postname%/',
        '/%year%/%monthnum%/%day%/%postname%/',
        '/%year%/%monthnum%/%postname%/',
        '/archives/%post_id%',
        '/%postname%',
        '/%year%/%monthnum%/%day%/%postname%',
        '/%year%/%monthnum%/%postname%',
    ];

    /**
     * The rows list a kind's addresses are kept under.
     */
    public static function listOf(string $kind): string
    {
        return 'urls-'.$kind;
    }

    /**
     * Writes the file's addresses afresh for a run, from the bases chosen:
     * each published item's own, each term's and author's, and each media
     * file's page and files. Authors and terms the file named only on its
     * items have no ID WordPress gave, so their addresses aren't listed.
     *
     * @param  array{category_base: string, tag_base: string}  $bases
     * @param  array<int, int>  $aliases  the map ID of each term an earlier import brought under another
     */
    public static function write(string $fileId, array $bases, array $aliases = []): void
    {
        $home = rtrim((string) DB::table('import_files')->where('id', $fileId)->value('site_address'), '/');
        $rows = fn (string $list) => DB::table('import_file_rows')->where('file_id', $fileId)->where('list', $list)->orderBy('object_id');
        $written = [];

        foreach ($rows(ItemRows::CONTENT)->whereRaw("payload->>'status' = 'publish'")->whereNotIn(DB::raw("payload->>'type'"), self::UNADDRESSED)
            ->get(['object_id', DB::raw("payload->>'type' as type"), DB::raw("payload->>'permalink' as permalink")]) as $row) {
            if ((string) $row->permalink !== '') {
                $written[] = self::row('post', (int) $row->object_id, ['type' => (string) $row->type, 'url' => (string) $row->permalink]);
            }
        }

        $terms = $rows(ItemRows::TERMS)->pluck('payload')->map(fn (mixed $payload): array => self::decoded($payload))->all();
        $byId = array_column($terms, null, 'id');

        foreach (self::termLinks($terms, $home, $bases) as $id => $link) {
            $taxonomy = (string) ($byId[$id]['taxonomy'] ?? '');
            $mapped = $aliases[$id] ?? $id;

            if ($mapped > 0 && $taxonomy !== 'post_format') {
                $written[] = self::row('term', $mapped, ['taxonomy' => $taxonomy, 'url' => $link]);
            }
        }

        foreach ($rows(ItemRows::USERS)->where('object_id', '>', 0)->get(['object_id', 'payload']) as $row) {
            $person = self::decoded($row->payload);
            $nicename = (string) ($person['nicename'] ?? '');

            if ($nicename !== '' && (int) ($person['published_posts'] ?? 0) > 0) {
                $written[] = self::row('author', (int) $row->object_id, ['url' => "{$home}/author/".rawurlencode($nicename).'/']);
            }
        }

        foreach ($rows(ItemRows::MEDIA)->get(['object_id', 'payload']) as $row) {
            $media = self::decoded($row->payload);
            $id = (int) $row->object_id;

            if (is_string($media['attachment_page'] ?? null) && $media['attachment_page'] !== '') {
                $written[] = self::row('attachment_page', $id, ['url' => $media['attachment_page']]);
            }

            $files = self::files($media);

            if ($files !== []) {
                $written[] = self::row('attachment_file', $id, ['files' => $files]);
            }
        }

        DB::table('import_file_rows')->where('file_id', $fileId)->where('list', 'like', 'urls-%')->delete();

        foreach (array_chunk($written, 500) as $chunk) {
            DB::table('import_file_rows')->insert(array_map(fn (array $row): array => [...$row, 'file_id' => $fileId], $chunk));
        }
    }

    /**
     * Each term's address on the old site, from the bases chosen: a
     * category's under its parents, as WordPress gives them, a tag's and
     * any other taxonomy's under its base or its name.
     *
     * @param  list<array<string, mixed>>  $terms
     * @param  array{category_base: string, tag_base: string}  $bases
     *
     * @return array<int, string>
     */
    public static function termLinks(array $terms, string $home, array $bases): array
    {
        $byId = [];

        foreach ($terms as $term) {
            $byId[(int) ($term['id'] ?? 0)] = $term;
        }

        $links = [];

        foreach ($byId as $id => $term) {
            $taxonomy = (string) ($term['taxonomy'] ?? '');
            $base = match ($taxonomy) {
                WordPressAddresses::CATEGORIES => $bases['category_base'],
                WordPressAddresses::TAGS => $bases['tag_base'],
                default => $taxonomy,
            };
            $slugs = [(string) ($term['slug'] ?? '')];
            $parent = (int) ($term['parent'] ?? 0);

            // A category's address holds its parents', at most as deep as WordPress walks.
            for ($depth = 0; $taxonomy === WordPressAddresses::CATEGORIES && $parent !== 0 && isset($byId[$parent]) && $depth < 20; $depth++) {
                array_unshift($slugs, (string) ($byId[$parent]['slug'] ?? ''));
                $parent = (int) ($byId[$parent]['parent'] ?? 0);
            }

            $links[$id] = $home.'/'.trim($base, '/').'/'.implode('/', $slugs).'/';
        }

        return $links;
    }

    /**
     * The bases the old site most likely put its category and tag pages
     * under: the first part most common among the links in the file's
     * content to its own categories and tags, else WordPress's own.
     *
     * @return array{category_base: string, tag_base: string}
     */
    public static function bases(string $fileId): array
    {
        $home = (string) DB::table('import_files')->where('id', $fileId)->value('site_address');
        $host = strtolower((string) parse_url($home, PHP_URL_HOST));
        $slugs = [WordPressAddresses::CATEGORIES => [], WordPressAddresses::TAGS => []];

        foreach (DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::TERMS)
            ->whereRaw("payload->>'taxonomy' IN ('category', 'post_tag')")
            ->get([DB::raw("payload->>'taxonomy' as taxonomy"), DB::raw("payload->>'slug' as slug")]) as $term) {
            $slugs[(string) $term->taxonomy][strtolower(rawurldecode((string) $term->slug))] = true;
        }

        $seen = [WordPressAddresses::CATEGORIES => [], WordPressAddresses::TAGS => []];

        if ($host !== '') {
            $contents = DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::CONTENT)
                ->whereRaw("payload->>'content' LIKE ?", ['%'.$host.'%'])->orderBy('object_id')->limit(2000)
                ->selectRaw("payload->>'content' as content")->pluck('content');

            foreach ($contents as $content) {
                preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', (string) $content, $found);

                foreach ($found[1] as $href) {
                    $parts = parse_url(html_entity_decode($href));

                    if (! is_array($parts) || strtolower(preg_replace('/^www\./', '', (string) ($parts['host'] ?? '')) ?? '') !== preg_replace('/^www\./', '', $host)) {
                        continue;
                    }

                    $segments = array_values(array_filter(explode('/', strtolower(rawurldecode((string) ($parts['path'] ?? '')))), fn (string $part): bool => $part !== ''));
                    $last = end($segments);

                    foreach ($slugs as $taxonomy => $known) {
                        if (count($segments) >= 2 && is_string($last) && isset($known[$last])) {
                            $seen[$taxonomy][$segments[0]] = ($seen[$taxonomy][$segments[0]] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        $common = function (array $counts, string $default): string {
            arsort($counts);

            return (string) (array_key_first($counts) ?? $default);
        };

        return [
            'category_base' => $common($seen[WordPressAddresses::CATEGORIES], self::CATEGORY_BASE),
            'tag_base' => $common($seen[WordPressAddresses::TAGS], self::TAG_BASE),
        ];
    }

    /**
     * The first of WordPress's own address structures that every published
     * post's address in the file follows, read after a language's prefix
     * where the site runs Polylang; empty when none does, or the file has no
     * published post. Only published posts count: WordPress exports an
     * unpublished draft's address as /?p=123, which says nothing of the
     * site's structure.
     */
    public static function structure(string $fileId): string
    {
        $posts = DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::CONTENT)
            ->whereRaw("payload->>'type' = 'post' AND payload->>'status' = 'publish'")
            ->limit(200)
            ->get([DB::raw("payload->>'id' as id"), DB::raw("payload->>'slug' as slug"), DB::raw("payload->>'date' as date"), DB::raw("payload->>'permalink' as permalink")]);

        if ($posts->isEmpty()) {
            return '';
        }

        // Polylang's languages, whose slugs are the prefixes it puts before a post's address.
        $prefixes = DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::OTHER_TERMS)
            ->whereRaw("payload->>'taxonomy' = 'language'")
            ->selectRaw("lower(payload->>'slug') as slug")
            ->pluck('slug')
            ->map(fn (mixed $slug): string => (string) $slug)
            ->filter(fn (string $slug): bool => $slug !== '')
            ->all();

        foreach (self::STRUCTURES as $structure) {
            $fits = $posts->every(fn (object $post): bool => self::follows($structure, self::unprefixed((string) $post->permalink, $prefixes), (string) $post->slug, (string) $post->date, (int) $post->id));

            if ($fits) {
                return $structure;
            }
        }

        return '';
    }

    /**
     * A post's address without a language's prefix as its first segment.
     *
     * @param  list<string>  $prefixes
     */
    private static function unprefixed(string $permalink, array $prefixes): string
    {
        $path = parse_url($permalink, PHP_URL_PATH);

        if ($prefixes === [] || ! is_string($path) || preg_match('~^/([^/]+)(/.*)$~', $path, $match) !== 1 || ! in_array(strtolower(rawurldecode($match[1])), $prefixes, true)) {
            return $permalink;
        }

        return $match[2];
    }

    /**
     * Whether a post's address is what the structure gives it.
     */
    private static function follows(string $structure, string $permalink, string $slug, string $date, int $id): bool
    {
        $path = parse_url($permalink, PHP_URL_PATH);

        if (! is_string($path) || $slug === '' || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $day) !== 1) {
            return false;
        }

        $wanted = strtr($structure, ['%postname%' => $slug, '%year%' => $day[1], '%monthnum%' => $day[2], '%day%' => $day[3], '%post_id%' => (string) $id]);

        return strtolower(rawurldecode($path)) === strtolower(rawurldecode($wanted));
    }

    /**
     * Every size of a media file's addresses, its full size first.
     *
     * @param  array<string, mixed>  $media
     *
     * @return array<string, string>
     */
    private static function files(array $media): array
    {
        $url = is_string($media['url'] ?? null) ? $media['url'] : '';

        if ($url === '') {
            return [];
        }

        $files = ['full' => $url];
        $folder = substr($url, 0, (int) strrpos($url, '/') + 1);

        foreach ((array) ($media['metadata']['sizes'] ?? []) as $name => $size) {
            if (is_array($size) && is_string($size['file'] ?? null) && $size['file'] !== '') {
                $files[(string) $name] = $folder.$size['file'];
            }
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @return array{list: string, object_id: int, payload: string}
     */
    private static function row(string $kind, int $id, array $fields): array
    {
        return ['list' => self::listOf($kind), 'object_id' => $id, 'payload' => json_encode(['id' => $id, 'kind' => $kind, ...$fields], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }
}
