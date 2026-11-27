<?php

/**
 * Turns the neutral items an import reader hands over into the file rows the
 * import engine already reads: people and terms first, so content can point
 * at them, then content and media in the file's order.
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
use Teme\Contracts\Import\ImportItem;
use Teme\Contracts\Import\ImportKind;

/**
 * The adapter of the import socket. A reader's source IDs are strings, and
 * the engine's rows use integers: people and terms take negative IDs, content
 * and media positive ones, in the order the file lists them. A reader's
 * statuses are Teme's words; the engine's rows keep the words WordPress
 * uses, so each status is translated here, inside core. Comments and menus
 * are not read this release, and are left out.
 *
 * @since next
 */
final class ReaderRows
{
    /** Teme's status words, and the words the engine's rows keep for them. */
    private const array STATUSES = [
        'published' => 'publish',
        'draft' => 'draft',
        'private' => 'private',
        'scheduled' => 'future',
        'trashed' => 'trash',
    ];

    /** @var array<string, int> the ID each reader person's source ID has */
    private array $people = [];

    /** @var array<string, int> the ID each login has, so a login is one person */
    private array $logins = [];

    /** @var array<string, array{id: int, taxonomy: string}> the ID and taxonomy each reader term's source ID has */
    private array $terms = [];

    /** @var array<string, int> the ID each reader post or page has, by its source ID */
    private array $posts = [];

    /** @var array<string, int> the ID each reader media item has, by its source ID */
    private array $media = [];

    private int $nextContent = 1;

    private int $nextName = -1;

    /**
     * The rows of a file's items, each as `list`, `id` and `payload`.
     *
     * @param  list<ImportItem>  $items
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     *
     * @since next
     */
    public static function build(array $items, ContentBlocks $blocks): array
    {
        $rows = new self;

        return $rows->rows($items, $blocks);
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
                $rows[] = $this->person($item);
            }
        }

        foreach ($items as $item) {
            if ($item->kind === ImportKind::TERM) {
                $rows[] = $this->term($item);
            }
        }

        foreach ($items as $item) {
            if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true)) {
                $this->posts[$item->id] = $this->nextContent++;
            } elseif ($item->kind === ImportKind::MEDIA) {
                $this->media[$item->id] = $this->nextContent++;
            }
        }

        foreach ($items as $item) {
            if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true)) {
                $rows[] = ['list' => ItemRows::CONTENT, 'id' => $this->posts[$item->id], 'payload' => $this->post($item, $blocks)];
            } elseif ($item->kind === ImportKind::MEDIA) {
                $rows[] = ['list' => ItemRows::MEDIA, 'id' => $this->media[$item->id], 'payload' => $this->media($item)];
            }
        }

        return $rows;
    }

    /**
     * @return array{list: string, id: int, payload: array<string, mixed>}
     */
    private function person(ImportItem $item): array
    {
        $login = (string) $item->fields['login'];

        if (! isset($this->logins[$login])) {
            $this->logins[$login] = $this->nextName--;
        }

        $id = $this->logins[$login];
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
        $id = $this->nextName--;
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
            'id' => $this->posts[$item->id],
            'type' => $item->kind,
            'status' => self::STATUSES[$item->status] ?? 'draft',
            'slug' => $item->slug,
            'title' => $item->title,
            'date' => $item->publishedAt,
            'date_gmt' => $item->publishedAt,
            'modified_gmt' => $item->modifiedAt,
            'author' => $this->people[$item->authorId ?? ''] ?? 0,
            'parent' => $item->parentId === null ? 0 : ($this->posts[$item->parentId] ?? 0),
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
     * @return array<string, mixed>
     */
    private function media(ImportItem $item): array
    {
        $url = (string) ($item->fields['url'] ?? '');
        $path = (string) parse_url($url, PHP_URL_PATH);

        return [
            'id' => $this->media[$item->id],
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
}
