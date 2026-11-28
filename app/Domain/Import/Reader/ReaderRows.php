<?php

/**
 * Turns the neutral items an import reader hands over into the file rows the
 * import engine already reads: people and terms first, so content can point
 * at them, then content, media, comments and menu items in the file's order.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Reader;

use App\Domain\Contents\Slugs;
use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\ItemRows;
use App\Domain\Import\File\Paragraphs;
use App\Domain\Import\File\TermRows;
use App\Domain\Import\File\UserRows;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;
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
 * @since 1.8.0
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

    /** The menu item types a reader may give, and the words the engine's rows keep for them. */
    private const array MENU_ITEM_TYPES = [
        'content' => 'post_type',
        'term' => 'taxonomy',
        'link' => 'custom',
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

    /** @var array<string, true> every login the file gives a person, so a login made up for another never takes one */
    private array $reserved = [];

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
     * @param  array{people?: array<string, int>, terms?: array<string, array{id: int, taxonomy: string}>, menus?: array<string, int>, reserved?: array<string, true>, logins?: array<string, int>}  $known  people, terms and menus already written from earlier pages
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     *
     * @since 1.8.0
     */
    public static function build(array $items, ContentBlocks $blocks, array $ids, array $known = []): array
    {
        $rows = new self($ids);
        $rows->people = $known['people'] ?? [];
        $rows->terms = $known['terms'] ?? [];
        $rows->menus = $known['menus'] ?? [];
        $rows->reserved = $known['reserved'] ?? [];
        $rows->logins = $known['logins'] ?? [];

        return $rows->rows($items, $blocks);
    }

    /**
     * People, terms and menus this page needs that earlier pages already
     * wrote, loaded only for the source IDs the page names (and the logins
     * its people would take), so memory stays flat across many pages.
     *
     * @param  list<ImportItem>  $items
     *
     * @return array{people: array<string, int>, terms: array<string, array{id: int, taxonomy: string}>, menus: array<string, int>, reserved: array<string, true>, logins: array<string, int>}
     *
     * @since next
     */
    public static function knownOf(string $fileId, string $address, string $reader, array $items): array
    {
        $peopleIds = [];
        $termIds = [];
        $menuIds = [];
        $loginKeys = [];

        foreach ($items as $item) {
            if ($item->kind === ImportKind::PERSON) {
                $peopleIds[$item->id] = true;
                $given = (string) ($item->fields['login'] ?? '');

                if ($given !== '') {
                    $loginKeys[$given] = true;
                } else {
                    $name = trim((string) ($item->fields['displayName'] ?? '')) ?: trim($item->title);
                    $base = Slugs::fromText($name !== '' ? $name : $item->id) ?: 'author';
                    $loginKeys[$base] = true;

                    for ($number = 2; $number <= 5; $number++) {
                        $loginKeys[$base.'-'.$number] = true;
                    }
                }
            }

            if (is_string($item->authorId) && $item->authorId !== '') {
                $peopleIds[$item->authorId] = true;
            }

            foreach ($item->termIds as $termId) {
                $termIds[$termId] = true;
            }

            if ($item->kind === ImportKind::TERM) {
                $termIds[$item->id] = true;

                if (is_string($item->parentId) && $item->parentId !== '') {
                    $termIds[$item->parentId] = true;
                }
            }

            if ($item->kind === ImportKind::MENU) {
                $menuIds[$item->id] = true;
            }

            if (is_string($item->menuId) && $item->menuId !== '') {
                $menuIds[$item->menuId] = true;
            }

            if ($item->itemType === 'term' && is_string($item->objectId) && $item->objectId !== '') {
                $termIds[$item->objectId] = true;
            }
        }

        $bySource = [];

        foreach ([
            ImportKind::PERSON => array_keys($peopleIds),
            ImportKind::TERM => array_keys($termIds),
            ImportKind::MENU => array_keys($menuIds),
        ] as $kind => $sourceIds) {
            if ($sourceIds === []) {
                continue;
            }

            foreach (array_chunk($sourceIds, 500) as $chunk) {
                foreach (DB::table('import_reader_ids')
                    ->where('address', $address)
                    ->where('reader', $reader)
                    ->where('object_type', $kind)
                    ->whereIn('source_id', $chunk)
                    ->get(['object_type', 'source_id', 'object_id']) as $row) {
                    $bySource[(string) $row->object_type][(string) $row->source_id] = (int) $row->object_id;
                }
            }
        }

        $people = $bySource[ImportKind::PERSON] ?? [];
        $enginePeople = array_values($people);
        $reserved = [];
        $logins = [];

        if ($enginePeople !== [] || $loginKeys !== []) {
            $query = DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::USERS);

            if ($enginePeople !== []) {
                $query->where(function ($inner) use ($enginePeople, $loginKeys): void {
                    $inner->whereIn('object_id', $enginePeople);

                    if ($loginKeys !== []) {
                        $inner->orWhereIn(DB::raw("payload->>'login'"), array_keys($loginKeys));
                    }
                });
            } else {
                $query->whereIn(DB::raw("payload->>'login'"), array_keys($loginKeys));
            }

            foreach ($query->get(['object_id', 'payload']) as $row) {
                $payload = is_string($row->payload) ? json_decode($row->payload, true) : [];
                $login = is_array($payload) ? (string) ($payload['login'] ?? '') : '';
                $engineId = (int) $row->object_id;
                $sourceId = array_search($engineId, $people, true);

                if (is_string($sourceId)) {
                    $people[$sourceId] = $engineId;
                }

                if ($login !== '') {
                    $reserved[$login] = true;
                    $logins[$login] = $engineId;
                }
            }
        }

        $terms = [];
        $menus = [];
        $engineTerms = array_values($bySource[ImportKind::TERM] ?? []);
        $engineMenus = array_values($bySource[ImportKind::MENU] ?? []);
        $engineIds = [...$engineTerms, ...$engineMenus];

        if ($engineIds !== []) {
            foreach (DB::table('import_file_rows')
                ->where('file_id', $fileId)
                ->whereIn('list', [ItemRows::TERMS, ItemRows::OTHER_TERMS])
                ->whereIn('object_id', $engineIds)
                ->get(['object_id', 'payload']) as $row) {
                $payload = is_string($row->payload) ? json_decode($row->payload, true) : [];

                if (! is_array($payload)) {
                    continue;
                }

                $taxonomy = (string) ($payload['taxonomy'] ?? '');
                $engineId = (int) $row->object_id;

                if ($taxonomy === 'nav_menu') {
                    $sourceId = array_search($engineId, $bySource[ImportKind::MENU] ?? [], true);

                    if (is_string($sourceId)) {
                        $menus[$sourceId] = $engineId;
                    }

                    continue;
                }

                $sourceId = array_search($engineId, $bySource[ImportKind::TERM] ?? [], true);

                if (is_string($sourceId)) {
                    $terms[$sourceId] = ['id' => $engineId, 'taxonomy' => $taxonomy];
                }
            }
        }

        return ['people' => $people, 'terms' => $terms, 'menus' => $menus, 'reserved' => $reserved, 'logins' => $logins];
    }

    /**
     * Whether a post or page status is one of Teme's words.
     */
    public static function postStatusKnown(string $status): bool
    {
        return isset(self::STATUSES[$status]);
    }

    /**
     * Whether a comment status is one of Teme's words.
     */
    public static function commentStatusKnown(string $status): bool
    {
        return isset(self::COMMENT_STATUSES[$status]);
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
            if ($item->kind === ImportKind::PERSON && (string) ($item->fields['login'] ?? '') !== '') {
                $this->reserved[(string) $item->fields['login']] = true;
            }
        }

        foreach ($items as $item) {
            if ($item->kind === ImportKind::PERSON) {
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
            } elseif ($item->kind === ImportKind::COMMENT) {
                // Written even when the post is on a later page; ResolveImportReaderRows fills post_id.
                $rows[] = ['list' => ItemRows::COMMENTS, 'id' => $this->idOf($item), 'payload' => $this->comment($item)];
            } elseif ($item->kind === ImportKind::MENU_ITEM) {
                // Written even when the menu is on a later page; ResolveImportReaderRows fills menu.
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
        $given = (string) ($item->fields['login'] ?? '');

        if ($given !== '' && isset($this->logins[$given])) {
            $this->people[$item->id] = $this->logins[$given];

            return null;
        }

        $login = $given !== '' ? $given : $this->freeLogin($item);
        $id = $this->idOf($item);
        $this->logins[$login] = $id;
        $this->people[$item->id] = $id;

        $payload = UserRows::fromAuthor($id, [
            'author_login' => $login,
            'author_email' => (string) ($item->fields['email'] ?? ''),
            'author_display_name' => (string) ($item->fields['displayName'] ?? ''),
        ]);
        $payload['source_id'] = $item->id;

        return [
            'list' => ItemRows::USERS,
            'id' => $id,
            'payload' => $payload,
        ];
    }

    /**
     * A login for a person who has none, made from their name as the
     * WordPress import makes a slug, with a number when another person of the
     * file already has it, so two people are never merged into one.
     */
    private function freeLogin(ImportItem $item): string
    {
        $name = trim((string) ($item->fields['displayName'] ?? '')) ?: trim($item->title);
        $base = Slugs::fromText($name !== '' ? $name : $item->id) ?: 'author';
        $login = $base;

        for ($number = 2; isset($this->logins[$login]) || isset($this->reserved[$login]); $number++) {
            $login = $base.'-'.$number;
        }

        return $login;
    }

    /**
     * @return array{list: string, id: int, payload: array<string, mixed>}
     */
    private function term(ImportItem $item): array
    {
        $taxonomy = $this->taxonomyName((string) ($item->fields['taxonomy'] ?? 'category'));
        $name = $item->title !== '' ? $item->title : (string) ($item->fields['name'] ?? '');
        $slug = $item->slug !== '' ? $item->slug : $name;
        $id = $this->idOf($item);
        $this->terms[$item->id] = ['id' => $id, 'taxonomy' => $taxonomy];
        $payload = TermRows::payload($id, $taxonomy, $name, $slug, (string) ($item->fields['description'] ?? ''), $this->ids[self::key(ImportKind::TERM, $item->parentId ?? '')] ?? 0, null);
        $refs = array_filter(['parent' => $item->parentId], fn (?string $value): bool => is_string($value) && $value !== '');

        return [
            'list' => TermRows::listOf($taxonomy),
            'id' => $id,
            'payload' => [
                ...$payload,
                'reader' => true,
                'source_id' => $item->id,
                'language' => $item->language,
                'translation_group' => $item->translationGroup,
                'translations' => [],
                ...($refs === [] ? [] : ['reader_refs' => $refs]),
            ],
        ];
    }

    /**
     * The name a reader's taxonomy goes by in the rows: the WordPress name
     * Teme gives a taxonomy it has one for, else the taxonomy's own key, as
     * a plugin's registered taxonomy has. Unknown words are refused before
     * this runs (ReadImportFile::unknownWord).
     */
    private function taxonomyName(string $word): string
    {
        $taxonomy = app(TaxonomyRegistry::class)->get($word);

        return $taxonomy->wordpress ?? $taxonomy->key;
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

        $refs = array_filter([
            'parent' => $item->parentId,
            'author' => $item->authorId,
            'featured' => $item->featuredMediaId,
        ], fn (?string $value): bool => is_string($value) && $value !== '');

        if ($item->termIds !== []) {
            $refs['terms'] = $item->termIds;
        }

        return [
            'reader' => true,
            'source_id' => $item->id,
            'id' => $this->idOf($item),
            'type' => $item->kind,
            'status' => self::STATUSES[$item->status] ?? 'draft',
            'slug' => $item->slug,
            'title' => $item->title,
            'date' => $item->publishedAt,
            'date_gmt' => $item->publishedAt,
            'modified_gmt' => $item->modifiedAt,
            'author' => $this->personId($item->authorId),
            'parent' => $this->contentId($item->parentId) ?? 0,
            'menu_order' => $item->order,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'password' => '',
            'sticky' => $item->sticky,
            'format' => null,
            'template' => null,
            'featured_media' => $item->featuredMediaId === null ? null : ($this->ids[self::key(ImportKind::MEDIA, $item->featuredMediaId)] ?? null),
            'terms' => $terms,
            'excerpt' => $item->excerpt,
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
            'language' => $item->language,
            'translation_group' => $item->translationGroup,
            'translations' => [],
            ...($refs === [] ? [] : ['reader_refs' => $refs]),
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
        $refs = array_filter([
            'on' => $item->postId,
            'parent' => $item->parentId,
            'author' => $item->authorId,
        ], fn (?string $value): bool => is_string($value) && $value !== '');

        return [
            'id' => $this->idOf($item),
            'source_id' => $item->id,
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
            'user_id' => $this->personId($item->authorId),
            ...($refs === [] ? [] : ['reader_refs' => $refs]),
        ];
    }

    /**
     * A menu, as the term of its navigation: the engine assembles its items
     * into the menu. Menus come across unplaced; the import plan chooses
     * where each one sits.
     *
     * @return array{list: string, id: int, payload: array<string, mixed>}
     */
    private function menu(ImportItem $item): array
    {
        $id = $this->idOf($item);
        $this->menus[$item->id] = $id;
        $slug = (string) ($item->fields['slug'] ?? '');
        $name = $item->title !== '' ? $item->title : $slug;

        $payload = TermRows::payload($id, 'nav_menu', $name, $slug !== '' ? $slug : $name, '', 0, null);
        $payload['source_id'] = $item->id;

        return ['list' => ItemRows::OTHER_TERMS, 'id' => $id, 'payload' => $payload];
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
            'content' => $this->contentId($item->objectId) ?? 0,
            'term' => $this->terms[(string) $item->objectId]['id'] ?? 0,
            default => 0,
        };
        $refs = array_filter([
            'menu' => $item->menuId,
            'parent' => $item->parentId,
            'object' => $item->objectId,
            'object_kind' => in_array($item->itemType, ['content', 'term'], true) ? $item->itemType : null,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        return [
            'menu' => $this->menuId($item->menuId) ?? 0,
            'id' => $this->idOf($item),
            'source_id' => $item->id,
            'parent' => $item->parentId === null ? 0 : ($this->ids[self::key(ImportKind::MENU_ITEM, $item->parentId)] ?? 0),
            'order' => $item->order,
            'type' => self::MENU_ITEM_TYPES[(string) $item->itemType] ?? 'custom',
            'object' => $this->objectWord($item),
            'object_id' => $object,
            'url' => $item->itemType === 'link' ? (string) ($item->fields['url'] ?? '') : '',
            'title' => $item->title,
            'target' => '',
            'attr_title' => '',
            'classes' => [],
            'xfn' => '',
            ...($refs === [] ? [] : ['reader_refs' => $refs]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function media(ImportItem $item): array
    {
        $url = (string) ($item->fields['url'] ?? '');
        $path = (string) parse_url($url, PHP_URL_PATH);

        $refs = array_filter([
            'parent' => $item->parentId,
            'author' => $item->authorId,
        ], fn (?string $value): bool => is_string($value) && $value !== '');

        return [
            'id' => $this->idOf($item),
            'source_id' => $item->id,
            'parent' => $this->contentId($item->parentId) ?? 0,
            'author' => $this->personId($item->authorId),
            'date_gmt' => $item->publishedAt,
            'modified_gmt' => $item->modifiedAt,
            'status' => 'inherit',
            'title' => $item->title,
            'caption' => (string) ($item->fields['caption'] ?? ''),
            'description' => (string) ($item->fields['description'] ?? ''),
            'alt' => (string) ($item->fields['alt'] ?? ''),
            'mime' => (string) ($item->fields['mime'] ?? '') ?: null,
            'file' => basename($path),
            'url' => $url !== '' ? $url : null,
            'original_url' => $url !== '' ? $url : null,
            'attachment_page' => $item->oldAddress ?? '',
            'metadata' => null,
            'backup_sizes' => [],
            ...($refs === [] ? [] : ['reader_refs' => $refs]),
        ];
    }

    /**
     * The engine's word for what a menu item's object is: a post's or page's
     * type, a term's taxonomy, or nothing for a link.
     */
    private function objectWord(ImportItem $item): string
    {
        return match ($item->itemType) {
            'content' => $this->contentKind($item->objectId) ?? '',
            'term' => $this->terms[(string) $item->objectId]['taxonomy'] ?? '',
            default => '',
        };
    }

    /**
     * The kind of the post or page a source ID names, as the engine's word for it.
     */
    private function contentKind(?string $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        return isset($this->ids[self::key(ImportKind::POST, $sourceId)]) ? 'post' : (isset($this->ids[self::key(ImportKind::PAGE, $sourceId)]) ? 'page' : null);
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

    /**
     * The engine ID of a person by their source ID, from this page or an earlier one.
     */
    private function personId(?string $sourceId): int
    {
        if ($sourceId === null || $sourceId === '') {
            return 0;
        }

        return $this->people[$sourceId] ?? $this->ids[self::key(ImportKind::PERSON, $sourceId)] ?? 0;
    }

    /**
     * The engine ID of a menu by its source ID, from this page or an earlier one.
     */
    private function menuId(?string $sourceId): ?int
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }

        return $this->menus[$sourceId] ?? $this->ids[self::key(ImportKind::MENU, $sourceId)] ?? null;
    }
}
