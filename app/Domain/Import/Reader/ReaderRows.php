<?php

/**
 * Turns the neutral items an import reader hands over into the file rows the
 * import engine already reads: people and terms first, so content can point
 * at them, then content, media, comments and menu items in the file's order.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Reader;

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\ItemRows;
use App\Domain\Import\File\Paragraphs;
use App\Domain\Import\File\TermRows;
use App\Domain\Import\File\UserRows;
use InvalidArgumentException;
use LogicException;
use Teme\Contracts\Import\ImportItem;
use Teme\Contracts\Import\ImportKind;

/**
 * The adapter of the import socket. A reader's source IDs are strings, and
 * the engine's rows use integers: each item's integer comes from
 * AssignImportReaderIds, which keeps it for the same site and reader, so a
 * re-import finds the same items. Statuses are Teme's words; the engine's
 * rows keep the words WordPress uses, so each one is translated here, inside
 * core.
 *
 * @since next
 */
final class ReaderRows
{
    /** A comment's Teme status and the words the engine's comment rows keep for it. */
    private const array COMMENT_STATUSES = [
        'published' => '1',
        'draft' => '0',
        'private' => '0',
        'scheduled' => '0',
        'trashed' => 'trash',
        'spam' => 'spam',
    ];

    /** Teme's status words, and the words the engine's rows keep for them. */
    private const array STATUSES = [
        'published' => 'publish',
        'draft' => 'draft',
        'private' => 'private',
        'scheduled' => 'future',
        'trashed' => 'trash',
    ];

    /** @var array<string, int> the engine's ID of each item, by key() */
    private array $ids;

    /** @var array<string, int> the engine's ID of each person, by source ID */
    private array $people = [];

    /** @var array<string, int> the ID of the first person with each login, so a login is one person */
    private array $logins = [];

    /** @var array<string, array{id: int, taxonomy: string}> the ID and taxonomy of each term, by source ID */
    private array $terms = [];

    /** @var array<string, int> the ID of each menu, by source ID */
    private array $menus = [];

    /**
     * @param  array<string, int>  $ids  the engine's ID of each item, by key()
     */
    private function __construct(array $ids)
    {
        $this->ids = $ids;
    }

    /**
     * The rows of a file's items, each as `list`, `id` and `payload`.
     *
     * @param  list<ImportItem>  $items
     * @param  array<string, int>  $ids  the engine's ID of each item, as AssignImportReaderIds gives them
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     *
     * @since next
     */
    public static function build(array $items, ContentBlocks $blocks, array $ids): array
    {
        return (new self($ids))->rows($items, $blocks);
    }

    /**
     * The key an item has in the IDs map: its kind and its source ID.
     */
    public static function key(string $kind, string $sourceId): string
    {
        return $kind."\0".$sourceId;
    }

    /**
     * The counter an item's engine ID is counted in. Items in one counter
     * never share an ID; content and media share one, as the engine's rows do.
     */
    public static function counterOf(string $kind): string
    {
        return match ($kind) {
            ImportKind::PERSON => 'users',
            ImportKind::TERM => 'terms',
            ImportKind::MENU => 'menus',
            ImportKind::POST, ImportKind::PAGE, ImportKind::MEDIA => 'content',
            ImportKind::COMMENT => 'comments',
            ImportKind::MENU_ITEM => 'menu-items',
            default => throw new InvalidArgumentException("No counter for the kind {$kind}."),
        };
    }

    /**
     * Whether a counter counts down from -1, as people, terms and menus do,
     * rather than up from 1.
     */
    public static function countsDown(string $counter): bool
    {
        return in_array($counter, ['users', 'terms', 'menus'], true);
    }

    /**
     * @param  list<ImportItem>  $items
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     */
    private function rows(array $items, ContentBlocks $blocks): array
    {
        $rows = [];

        foreach ($items as $item) {
            if ($item->kind === ImportKind::PERSON && ($item->fields['login'] ?? '') !== '') {
                $person = $this->person($item);

                if ($person !== null) {
                    $rows[] = $person;
                }
            }
        }

        foreach ($items as $item) {
            if ($item->kind === ImportKind::TERM) {
                $rows[] = $this->term($item);
            } elseif ($item->kind === ImportKind::MENU) {
                $rows[] = $this->menu($item);
            }
        }

        foreach ($items as $item) {
            if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true)) {
                $rows[] = ['list' => ItemRows::CONTENT, 'id' => $this->idOf($item), 'payload' => $this->post($item, $blocks)];
            } elseif ($item->kind === ImportKind::MEDIA) {
                $rows[] = ['list' => ItemRows::MEDIA, 'id' => $this->idOf($item), 'payload' => $this->media($item)];
            } elseif ($item->kind === ImportKind::COMMENT && $this->contentId($item->postId) !== null) {
                $rows[] = ['list' => ItemRows::COMMENTS, 'id' => $this->idOf($item), 'payload' => $this->comment($item)];
            } elseif ($item->kind === ImportKind::MENU_ITEM && isset($this->menus[(string) $item->menuId])) {
                $rows[] = ['list' => ItemRows::MENU_ITEMS, 'id' => $this->idOf($item), 'payload' => $this->menuItem($item)];
            }
        }

        return $rows;
    }

    /**
     * A person, or null when an earlier person has the same login: one login is one row.
     *
     * @return array{list: string, id: int, payload: array<string, mixed>}|null
     */
    private function person(ImportItem $item): ?array
    {
        $login = (string) $item->fields['login'];

        if (isset($this->logins[$login])) {
            $this->people[$item->id] = $this->logins[$login];

            return null;
        }

        $id = $this->idOf($item);
        $this->logins[$login] = $id;
        $this->people[$item->id] = $id;

        return [
            'list' => ItemRows::USERS,
            'id' => $id,
            'payload' => UserRows::fromAuthor($id, [
                'author_login' => $login,
                'author_email' => (string) ($item->fields['email'] ?? ''),
                'author_display_name' => (string) ($item->fields['displayName'] ?? ''),
            ]),
        ];
    }

    /**
     * @return array{list: string, id: int, payload: array<string, mixed>}
     */
    private function term(ImportItem $item): array
    {
        $taxonomy = (string) ($item->fields['taxonomy'] ?? 'category');
        $name = $item->title !== '' ? $item->title : (string) ($item->fields['name'] ?? '');
        $slug = $item->slug !== '' ? $item->slug : $name;
        $id = $this->idOf($item);
        $this->terms[$item->id] = ['id' => $id, 'taxonomy' => $taxonomy];

        return [
            'list' => TermRows::listOf($taxonomy),
            'id' => $id,
            'payload' => TermRows::payload($id, $taxonomy, $name, $slug, (string) ($item->fields['description'] ?? ''), 0, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function post(ImportItem $item, ContentBlocks $blocks): array
    {
        $content = $item->format === 'plain' ? Paragraphs::make(htmlspecialchars($item->content)) : $item->content;
        $terms = [];

        foreach ($item->termIds as $termId) {
            if (isset($this->terms[$termId])) {
                $terms[$this->terms[$termId]['taxonomy']][] = $this->terms[$termId]['id'];
            }
        }

        return [
            'id' => $this->idOf($item),
            'type' => $item->kind,
            'status' => self::STATUSES[$item->status] ?? 'draft',
            'slug' => $item->slug,
            'title' => $item->title,
            'date' => $item->publishedAt,
            'date_gmt' => $item->publishedAt,
            'modified_gmt' => $item->modifiedAt,
            'author' => $this->people[$item->authorId ?? ''] ?? 0,
            'parent' => $this->contentId($item->parentId) ?? 0,
            'menu_order' => 0,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'password' => '',
            'sticky' => false,
            'format' => null,
            'template' => null,
            'featured_media' => null,
            'terms' => $terms,
            'excerpt' => '',
            'content' => $content,
            'has_blocks' => str_contains($content, '<!-- wp:'),
            'blocks' => $blocks->tree($content),
            'permalink' => $item->oldAddress ?? '',
            'old_slugs' => [],
            'old_dates' => [],
            'seo' => null,
            'comment_count' => 0,
            'status_before_trash' => null,
            'trashed_at' => null,
        ];
    }

    /**
     * A comment as the engine's comment rows hold it: on its post, with its
     * parent comment and its author's fields. Teme's statuses become the
     * words the comments keep.
     *
     * @return array<string, mixed>
     */
    private function comment(ImportItem $item): array
    {
        return [
            'id' => $this->idOf($item),
            'post_id' => $this->contentId($item->postId),
            'parent' => $item->parentId === null ? 0 : ($this->ids[self::key(ImportKind::COMMENT, $item->parentId)] ?? 0),
            'type' => 'comment',
            'status' => self::COMMENT_STATUSES[$item->status] ?? '0',
            'author_name' => (string) ($item->fields['authorName'] ?? ''),
            'author_email' => (string) ($item->fields['authorEmail'] ?? ''),
            'author_url' => (string) ($item->fields['authorUrl'] ?? ''),
            'author_ip' => (string) ($item->fields['authorIp'] ?? ''),
            'user_agent' => null,
            'date_gmt' => $item->publishedAt,
            'content' => $item->content,
            'user_id' => $this->people[$item->authorId ?? ''] ?? 0,
        ];
    }

    /**
     * A menu, as the term of its navigation: the engine assembles its items
     * into the menu. Where it is placed is chosen on the import plan, so a
     * reader's placement is carried but not used.
     *
     * @return array{list: string, id: int, payload: array<string, mixed>}
     */
    private function menu(ImportItem $item): array
    {
        $id = $this->idOf($item);
        $this->menus[$item->id] = $id;
        $slug = (string) ($item->fields['slug'] ?? '');
        $name = $item->title !== '' ? $item->title : $slug;

        return ['list' => ItemRows::OTHER_TERMS, 'id' => $id, 'payload' => TermRows::payload($id, 'nav_menu', $name, $slug !== '' ? $slug : $name, '', 0, null)];
    }

    /**
     * A menu item in the engine's menu item rows: in its menu, under its
     * parent item, pointing at the post or term it names.
     *
     * @return array<string, mixed>
     */
    private function menuItem(ImportItem $item): array
    {
        $object = match ($item->itemType) {
            'post_type' => $this->contentId($item->objectId) ?? 0,
            'taxonomy' => $this->terms[(string) $item->objectId]['id'] ?? 0,
            default => 0,
        };

        return [
            'menu' => $this->menus[(string) $item->menuId],
            'id' => $this->idOf($item),
            'parent' => $item->parentId === null ? 0 : ($this->ids[self::key(ImportKind::MENU_ITEM, $item->parentId)] ?? 0),
            'order' => $item->order,
            'type' => (string) $item->itemType,
            'object' => (string) ($item->fields['object'] ?? ''),
            'object_id' => $object,
            'url' => $item->itemType === 'custom' ? (string) ($item->fields['url'] ?? '') : '',
            'title' => $item->title,
            'target' => '',
            'attr_title' => '',
            'classes' => [],
            'xfn' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function media(ImportItem $item): array
    {
        $url = (string) ($item->fields['url'] ?? '');
        $path = (string) parse_url($url, PHP_URL_PATH);

        return [
            'id' => $this->idOf($item),
            'parent' => 0,
            'author' => 0,
            'date_gmt' => $item->publishedAt,
            'modified_gmt' => $item->modifiedAt,
            'status' => 'inherit',
            'title' => $item->title,
            'caption' => '',
            'description' => '',
            'alt' => (string) ($item->fields['alt'] ?? ''),
            'mime' => (string) ($item->fields['mime'] ?? '') ?: null,
            'file' => basename($path),
            'url' => $url !== '' ? $url : null,
            'original_url' => $url !== '' ? $url : null,
            'attachment_page' => $item->oldAddress ?? '',
            'metadata' => null,
            'backup_sizes' => [],
        ];
    }

    /**
     * The engine ID of an item of the file. Every item read has one, so a missing one is a bug.
     */
    private function idOf(ImportItem $item): int
    {
        return $this->ids[self::key($item->kind, $item->id)]
            ?? throw new LogicException("No engine ID was given to the reader item {$item->kind} {$item->id}.");
    }

    /**
     * The engine ID of a post or page by its source ID; a post wins over a
     * page with the same ID, which the contract keeps unique per kind only.
     */
    private function contentId(?string $sourceId): ?int
    {
        if ($sourceId === null) {
            return null;
        }

        return $this->ids[self::key(ImportKind::POST, $sourceId)] ?? $this->ids[self::key(ImportKind::PAGE, $sourceId)] ?? null;
    }
}
