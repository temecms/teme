<?php

/**
 * Turns one item of an export file into working rows shaped as the
 * companion's answers (docs/architecture/import.md, The export API): an
 * attachment into `media`, a menu item kept for its menu, block navigation
 * into `navigation`, anything else into `content`, with its comments, plus
 * what the file's report counts from it.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use App\Domain\Import\WordPressRecords;

final readonly class ItemRows
{
    public const string USERS = 'users';

    public const string TERMS = 'terms';

    public const string CONTENT = 'content';

    public const string MEDIA = 'media';

    public const string COMMENTS = 'comments';

    public const string MENUS = 'menus';

    public const string NAVIGATION = 'navigation';

    /** The file's own lists, which the companion has no route for: menu items before they join their menus, and the terms `terms` leaves out. */
    public const string MENU_ITEMS = 'file-menu-items';

    public const string OTHER_TERMS = 'file-terms';

    /** The file's one row of what its report counts as it is read, at ID zero. */
    public const string FACTS = 'file-facts';

    public function __construct(
        private FileLookups $lookups,
        private ContentBlocks $blocks,
        private string $siteHost,
    ) {}

    /**
     * Returns the item's rows, then the authors and terms it named that the
     * file didn't list, and what the report counts from it.
     *
     * @param  array{fields: array<string, string>, taxonomies: list<array{domain: string, nicename: string, name: string}>, meta: list<array{key: string, value: string}>, comments: list<array{fields: array<string, string>, meta: list<array{key: string, value: string}>}>}  $item
     *
     * @return array{rows: list<array{list: string, id: int, payload: array<string, mixed>}>, facts: array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}}
     */
    public function read(array $item): array
    {
        $facts = ['seo' => [], 'images' => ['total' => 0, 'own' => 0, 'hosts' => []], 'dropped' => []];
        $id = (int) ($item['fields']['wp:post_id'] ?? 0);
        $type = trim($item['fields']['wp:post_type'] ?? 'post');

        // WordPress's own records, revisions and unsaved drafts among them, never come across.
        if ($id <= 0 || WordPressRecords::internal($type, trim($item['fields']['wp:status'] ?? ''))) {
            return ['rows' => [], 'facts' => $facts];
        }

        $meta = [];
        $serialized = [];

        foreach ($item['meta'] as $pair) {
            if (in_array($pair['key'], SerializedMeta::KEYS, true)) {
                $read = SerializedMeta::read($pair['key'], $pair['value']);
                $serialized[$pair['key']] = $read['value'];

                if ($read['dropped']) {
                    $facts['dropped'][] = ['post_id' => $id, 'key' => $pair['key']];
                }

                continue;
            }

            $meta[$pair['key']][] = $pair['value'];
        }

        $rows = match ($type) {
            'attachment' => [['list' => self::MEDIA, 'id' => $id, 'payload' => MediaRows::payload($item, $meta, $serialized, $this->lookups)]],
            'nav_menu_item' => array_filter([MenuRows::item($item, $meta, $serialized, $this->lookups)]),
            'wp_navigation' => [['list' => self::NAVIGATION, 'id' => $id, 'payload' => [
                'id' => $id,
                'title' => $item['fields']['title'] ?? '',
                'status' => trim($item['fields']['wp:status'] ?? ''),
                'blocks' => $this->blocks->parsed($item['fields']['content:encoded'] ?? ''),
            ]]],
            default => $this->content($item, $meta, $serialized, $facts),
        };

        return ['rows' => [...$rows, ...$this->lookups->takeAdded()], 'facts' => $facts];
    }

    /**
     * @param  array{fields: array<string, string>, taxonomies: list<array{domain: string, nicename: string, name: string}>, meta: list<array{key: string, value: string}>, comments: list<array{fields: array<string, string>, meta: list<array{key: string, value: string}>}>}  $item
     * @param  array<string, list<string>>  $meta
     * @param  array<string, array<mixed>|null>  $serialized
     * @param  array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}  $facts
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     */
    private function content(array $item, array $meta, array $serialized, array &$facts): array
    {
        $payload = ContentRows::payload($item, $meta, $serialized, $this->lookups, $this->blocks);
        $plugin = ContentRows::seoPlugin($meta, $serialized);

        if ($plugin !== null) {
            $facts['seo'][] = $plugin;
        }

        $facts['images'] = $this->images((string) $payload['content']);

        return [
            ['list' => self::CONTENT, 'id' => (int) $payload['id'], 'payload' => $payload],
            ...CommentRows::rows((int) $payload['id'], $item['comments']),
        ];
    }

    /**
     * The images in the content: how many, how many on the old site itself, and the other hosts.
     *
     * @return array{total: int, own: int, hosts: array<string, int>}
     */
    private function images(string $content): array
    {
        $images = ['total' => 0, 'own' => 0, 'hosts' => []];
        preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/i', $content, $found);

        foreach ($found[1] as $src) {
            $images['total']++;
            $host = parse_url(html_entity_decode($src), PHP_URL_HOST);

            if (! is_string($host) || $host === '' || self::bare($host) === self::bare($this->siteHost)) {
                $images['own']++;

                continue;
            }

            $host = strtolower($host);
            $images['hosts'][$host] = ($images['hosts'][$host] ?? 0) + 1;
        }

        return $images;
    }

    private static function bare(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
