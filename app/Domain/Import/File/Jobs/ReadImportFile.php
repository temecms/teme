<?php

/**
 * Reads an uploaded WordPress export file into import file rows, a part at a
 * time, as the person who uploaded it. Dispatched when an upload finishes.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File\Jobs;

use App\Domain\Import\File\ChannelRows;
use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\ItemRows;
use App\Domain\Import\File\TermRows;
use App\Domain\Import\File\WxrRefused;
use App\Domain\Import\File\WxrStream;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\AssignImportReaderIds;
use App\Domain\Import\Operations\FinishReadingImportFile;
use App\Domain\Import\Operations\Input\AssignImportReaderIdsInput;
use App\Domain\Import\Operations\Input\FinishReadingImportFileInput;
use App\Domain\Import\Operations\Input\RecordImportFileRowsInput;
use App\Domain\Import\Operations\Input\RefuseImportFileInput;
use App\Domain\Import\Operations\RecordImportFileRows;
use App\Domain\Import\Operations\RefuseImportFile;
use App\Domain\Import\Reader\ReaderRows;
use App\Domain\Import\Reader\ReaderSite;
use App\Domain\Plugins\Readers\ImportReaders;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Support\Security\JobActor;
use App\Domain\Types\TaxonomyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Import\ImportItem;
use Teme\Contracts\Import\ImportKind;
use Teme\Contracts\Import\ImportMenuItemType;
use Teme\Contracts\Import\ImportProbe;
use Teme\Contracts\Import\ImportReader;
use Teme\Contracts\Import\ImportReaderRefused;
use Teme\Contracts\Import\ImportTaxonomy;
use Throwable;

final class ReadImportFile implements ShouldQueue
{
    use Queueable;

    /**
     * The seconds a part of the read takes at most before it keeps what it
     * read and queues the next part, as a page of a list does
     * (ImportListPage::SECONDS).
     */
    public const int SECONDS = 45;

    /** The items kept together, so a long part writes as it goes. */
    public const int ITEMS = 500;

    /**
     * The bytes of row payloads, as JSON, kept together at most: block-heavy
     * items write sooner than ITEMS, so a worker's memory stays small.
     *
     * @since 1.4.0
     */
    public const int BYTES = 8 * 1024 * 1024;

    /** Under the database queue's retry_after of 90 seconds, so no second worker takes the file while this one reads it. */
    public int $timeout = 75;

    public function __construct(
        public readonly string $fileId,
        public readonly JobActor $actor,
    ) {}

    /**
     * Reads the next part of the file, from the item after the last one kept;
     * at the end, finishes the read, and refuses a file that isn't an export.
     */
    public function handle(): void
    {
        $deadline = CarbonImmutable::now()->addSeconds(self::SECONDS);
        $this->actor->bind();
        $file = ImportFile::query()->find($this->fileId);

        if ($file === null || $file->state !== 'reading' || $file->deleted_at !== null) {
            return;
        }

        if ($file->reader !== null) {
            $this->readReader($file, $deadline);

            return;
        }

        try {
            $stream = WxrStream::open(Storage::disk('local')->path($file->path()));
            $channel = $stream->channel();
            $facts = ['siteAddress' => ChannelRows::siteAddress($channel), 'version' => $channel['version'], 'madeAt' => $channel['pubDate']];
            [$authors, $terms, $lowest] = $this->readSoFar($file->id);
            $start = ChannelRows::read($channel, $authors, $terms, $lowest);
            $reader = new ItemRows($start['lookups'], $this->blocks(), (string) parse_url((string) $facts['siteAddress'], PHP_URL_HOST));

            $from = $file->read_items;
            $at = $from;
            $rows = $from === 0 ? $start['rows'] : [];
            $counted = self::noFacts();
            $bytes = 0;

            foreach ($stream->items($from) as $index => $item) {
                $late = CarbonImmutable::now()->greaterThanOrEqualTo($deadline);

                if ($at > $from && ($late || $at - $from >= self::ITEMS || $bytes >= self::BYTES)) {
                    if (! $this->keep($from, $at, $rows, $counted, $facts)) {
                        return;
                    }

                    [$from, $rows, $counted, $bytes] = [$at, [], self::noFacts(), 0];

                    if ($late) {
                        dispatch(new self($this->fileId, $this->actor));

                        return;
                    }
                }

                $read = $reader->read($item);
                unset($item);

                foreach ($read['rows'] as $row) {
                    $bytes += strlen((string) json_encode($row['payload']));
                    $rows[] = $row;
                }

                $counted = self::added($counted, $read['facts']);
                unset($read);
                $at = $index + 1;
            }

            if (! $this->keep($from, $at, $rows, $counted, $facts)) {
                return;
            }

            app(FinishReadingImportFile::class)->execute(new FinishReadingImportFileInput($this->fileId, $this->actor->userId));
        } catch (WxrRefused $refusal) {
            app(RefuseImportFile::class)->execute(new RefuseImportFileInput($this->fileId, $refusal->sentence, $this->actor->userId));
        }
    }

    /**
     * Reads a file a plugin's import reader reads, a page at a time through
     * keep(), as a WordPress export is. A stored cursor resumes the next
     * part; references to later pages are resolved once every page is kept.
     * A refusal stops the file with its sentence; any other failure is
     * recorded as the plugin's fault and stops it too.
     */
    private function readReader(ImportFile $file, CarbonImmutable $deadline): void
    {
        $readers = app(ImportReaders::class);
        $offer = $readers->find((string) $file->reader);

        if ($offer === null) {
            $this->refuseReader('That import reader isn’t on this site any more.');

            return;
        }

        $path = Storage::disk('local')->path($file->path());
        $state = $this->readerState($file);

        try {
            $reader = $readers->reader($offer);
            $probe = $reader->probe($path);
        } catch (ImportReaderRefused $refusal) {
            $this->refuseReader($refusal->getMessage());

            return;
        } catch (Throwable $failure) {
            app(SeoPlugins::class)->fault($offer->plugin, 'import-reader', ImportReader::class, $failure);
            $this->refuseReader('The import reader couldn’t read this file.');

            return;
        }

        if (trim((string) $probe->siteAddress) === '') {
            $this->refuseReader('This export doesn’t name its site, so Teme can’t tell where it came from.');

            return;
        }

        $channel = ['siteAddress' => $probe->siteAddress, 'version' => $probe->version, 'madeAt' => null];
        $address = ReaderSite::address((string) $probe->siteAddress);
        $readerKey = (string) $file->reader;

        if ($state['resolving']) {
            $this->resolveReader($file, $reader, $path, $probe, $address, $readerKey, $channel, $deadline, $state);

            return;
        }

        $from = $file->read_items;
        $cursor = $state['cursor'];
        $given = $state['given'];

        try {
            do {
                $page = $reader->page($path, $cursor, self::readerPageSize());
                $next = $page->nextCursor;

                if ($next !== null && isset($given[$next])) {
                    $this->refuseReader('The import reader repeated a page of this file, so Teme stopped reading it.');

                    return;
                }

                if ($next !== null) {
                    $given[$next] = true;
                }

                $unknown = $this->unknownWord($page->items);

                if ($unknown !== null) {
                    $this->refuseReader($unknown);

                    return;
                }

                $ids = app(AssignImportReaderIds::class)->execute(new AssignImportReaderIdsInput(
                    $address,
                    $readerKey,
                    array_map(fn (ImportItem $item): array => ['kind' => $item->kind, 'id' => $item->id], $page->items),
                    $this->actor->userId,
                ));
                $ids = $this->withReferencedIds($ids, $page->items, $address, $readerKey);
                $known = $from === 0 ? [] : ReaderRows::knownOf($file->id, $address, $readerKey);
                $rows = ReaderRows::build($page->items, $this->blocks(), $ids, $known);
                $to = $from + count($page->items);
                $counted = self::noFacts();
                $counted['readerCursor'] = $next;
                $counted['readerGiven'] = array_keys($given);
                $counted['readerResolving'] = $next === null;

                if (! $this->keep($from, $to, $rows, $counted, $channel)) {
                    return;
                }

                if ($next === null) {
                    $file->refresh();
                    $this->resolveReader($file, $reader, $path, $probe, $address, $readerKey, $channel, $deadline, [
                        'cursor' => null,
                        'given' => $given,
                        'resolving' => true,
                    ]);

                    return;
                }

                $from = $to;
                $cursor = $next;

                if (self::outOfTime($deadline)) {
                    dispatch(new self($this->fileId, $this->actor));

                    return;
                }
            } while (true);
        } catch (ImportReaderRefused $refusal) {
            $this->refuseReader($refusal->getMessage());
        } catch (Throwable $failure) {
            app(SeoPlugins::class)->fault($offer->plugin, 'import-reader', ImportReader::class, $failure);
            $this->refuseReader('The import reader couldn’t read this file.');
        }
    }

    /**
     * The cursor, given cursors and whether this part resolves later-page
     * references, as the last keep stored them.
     *
     * @return array{cursor: string|null, given: array<string, true>, resolving: bool}
     */
    private function readerState(ImportFile $file): array
    {
        $stored = DB::table('import_file_rows')->where(['file_id' => $file->id, 'list' => ItemRows::FACTS, 'object_id' => 0])->value('payload');
        $facts = is_string($stored) ? json_decode($stored, true) : null;
        $facts = is_array($facts) ? $facts : [];
        $given = [];

        foreach (is_array($facts['readerGiven'] ?? null) ? $facts['readerGiven'] : [] as $cursor) {
            if (is_string($cursor) && $cursor !== '') {
                $given[$cursor] = true;
            }
        }

        $cursor = $facts['readerCursor'] ?? null;

        return [
            'cursor' => is_string($cursor) && $cursor !== '' ? $cursor : null,
            'given' => $given,
            'resolving' => ($facts['readerResolving'] ?? false) === true,
        ];
    }

    /**
     * Engine IDs already kept for items this page names as parents, authors,
     * terms, pictures, posts, menus or targets, so an earlier page's item is
     * found without loading every ID the site has.
     *
     * @param  array<string, int>  $ids
     * @param  list<ImportItem>  $items
     *
     * @return array<string, int>
     */
    private function withReferencedIds(array $ids, array $items, string $address, string $reader): array
    {
        $wanted = [];

        foreach ($items as $item) {
            foreach ([
                [ImportKind::POST, $item->parentId],
                [ImportKind::PAGE, $item->parentId],
                [ImportKind::PERSON, $item->authorId],
                [ImportKind::MEDIA, $item->featuredMediaId],
                [ImportKind::POST, $item->postId],
                [ImportKind::PAGE, $item->postId],
                [ImportKind::MENU, $item->menuId],
                [ImportKind::COMMENT, $item->parentId],
                [ImportKind::MENU_ITEM, $item->parentId],
                [ImportKind::TERM, $item->parentId],
                [ImportKind::MEDIA, $item->parentId],
            ] as [$kind, $sourceId]) {
                if (is_string($sourceId) && $sourceId !== '' && ! isset($ids[ReaderRows::key($kind, $sourceId)])) {
                    $wanted[$kind][$sourceId] = true;
                }
            }

            foreach ($item->termIds as $termId) {
                if (! isset($ids[ReaderRows::key(ImportKind::TERM, $termId)])) {
                    $wanted[ImportKind::TERM][$termId] = true;
                }
            }

            if (is_string($item->objectId) && $item->objectId !== '') {
                foreach ([ImportKind::POST, ImportKind::PAGE, ImportKind::TERM] as $kind) {
                    if (! isset($ids[ReaderRows::key($kind, $item->objectId)])) {
                        $wanted[$kind][$item->objectId] = true;
                    }
                }
            }
        }

        foreach ($wanted as $kind => $sourceIds) {
            foreach (array_chunk(array_keys($sourceIds), 500) as $chunk) {
                foreach (DB::table('import_reader_ids')
                    ->where('address', $address)
                    ->where('reader', $reader)
                    ->where('object_type', $kind)
                    ->whereIn('source_id', $chunk)
                    ->get(['object_type', 'source_id', 'object_id']) as $row) {
                    $ids[ReaderRows::key((string) $row->object_type, (string) $row->source_id)] = (int) $row->object_id;
                }
            }
        }

        return $ids;
    }

    /**
     * Rewrites each page's rows once every item has an ID, so parents,
     * featured pictures, terms and menu targets on later pages resolve, then
     * finishes the read. Continues in another job when the deadline is up.
     *
     * @param  array{siteAddress: string|null, version: string, madeAt: string|null}  $channel
     * @param  array{cursor: string|null, given: array<string, true>, resolving: bool}  $state
     */
    private function resolveReader(
        ImportFile $file,
        ImportReader $reader,
        string $path,
        ImportProbe $probe,
        string $address,
        string $readerKey,
        array $channel,
        CarbonImmutable $deadline,
        array $state,
    ): void {
        $cursor = $state['cursor'];
        $given = $state['given'];
        $presence = $this->readerPresence($address, $readerKey);

        try {
            do {
                $page = $reader->page($path, $cursor, self::readerPageSize());
                $next = $page->nextCursor;
                $ids = app(AssignImportReaderIds::class)->execute(new AssignImportReaderIdsInput(
                    $address,
                    $readerKey,
                    array_map(fn (ImportItem $item): array => ['kind' => $item->kind, 'id' => $item->id], $page->items),
                    $this->actor->userId,
                ));
                $ids = $this->withReferencedIds($ids, $page->items, $address, $readerKey);
                $known = ReaderRows::knownOf($file->id, $address, $readerKey);
                $rows = ReaderRows::build($page->items, $this->blocks(), $ids, $known);

                $at = (int) $file->read_items;
                $counted = self::noFacts();
                $counted['readerCursor'] = $next;
                $counted['readerGiven'] = array_keys($given);
                $counted['readerResolving'] = true;

                if ($next === null) {
                    $counted['notes'] = $this->notesAcross($reader, $path, $probe->languages, $presence);
                    $counted['replaceNotes'] = true;
                    $counted['readerResolving'] = false;
                    $counted['readerCursor'] = null;
                }

                if (! $this->keep($at, $at, $rows, $counted, $channel)) {
                    return;
                }

                if ($next === null) {
                    app(FinishReadingImportFile::class)->execute(new FinishReadingImportFileInput($this->fileId, $this->actor->userId));

                    return;
                }

                $cursor = $next;
                $file->refresh();

                if (self::outOfTime($deadline)) {
                    dispatch(new self($this->fileId, $this->actor));

                    return;
                }
            } while (true);
        } catch (ImportReaderRefused $refusal) {
            $this->refuseReader($refusal->getMessage());
        } catch (Throwable $failure) {
            $offer = app(ImportReaders::class)->find($readerKey);

            if ($offer !== null) {
                app(SeoPlugins::class)->fault($offer->plugin, 'import-reader', ImportReader::class, $failure);
            }

            $this->refuseReader('The import reader couldn’t read this file.');
        }
    }

    /**
     * The file's notes, gathered a page at a time with the whole file's
     * presence, so a large file never holds every item for the report.
     *
     * @param  list<string>  $languages
     * @param  array{content: array<string, true>, term: array<string, true>, media: array<string, true>, menu: array<string, true>, comment: array<string, true>, menuItem: array<string, true>}  $presence
     *
     * @return array<string, array{count: int, examples: list<string>, sentence: string}>
     */
    private function notesAcross(ImportReader $reader, string $path, array $languages, array $presence): array
    {
        $notes = [];
        $cursor = null;
        $first = true;

        do {
            $page = $reader->page($path, $cursor, self::readerPageSize());

            foreach ($this->notesOf($page->items, $first ? $languages : [], $presence) as $kind => $note) {
                $held = $notes[$kind] ?? ['count' => 0, 'examples' => [], 'sentence' => $note['sentence']];
                $notes[$kind] = [
                    'count' => $held['count'] + $note['count'],
                    'examples' => array_slice([...$held['examples'], ...$note['examples']], 0, 5),
                    'sentence' => $held['sentence'],
                ];
            }

            $first = false;
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $notes;
    }

    /**
     * Every source ID a reader has been given an engine ID for, by kind, so
     * notes can tell what the file holds without gathering every item again.
     *
     * @return array{content: array<string, true>, term: array<string, true>, media: array<string, true>, menu: array<string, true>, comment: array<string, true>, menuItem: array<string, true>}
     */
    private function readerPresence(string $address, string $reader): array
    {
        $presence = ['content' => [], 'term' => [], 'media' => [], 'menu' => [], 'comment' => [], 'menuItem' => []];

        foreach (DB::table('import_reader_ids')->where('address', $address)->where('reader', $reader)->get(['object_type', 'source_id']) as $row) {
            $id = (string) $row->source_id;

            match ((string) $row->object_type) {
                ImportKind::POST, ImportKind::PAGE => $presence['content'][$id] = true,
                ImportKind::TERM => $presence['term'][$id] = true,
                ImportKind::MEDIA => $presence['media'][$id] = true,
                ImportKind::MENU => $presence['menu'][$id] = true,
                ImportKind::COMMENT => $presence['comment'][$id] = true,
                ImportKind::MENU_ITEM => $presence['menuItem'][$id] = true,
                default => null,
            };
        }

        return $presence;
    }

    /**
     * What a reader's file holds that Teme doesn't bring, counted and named
     * for the report: comments on posts the file doesn't have, menu items in
     * menus it doesn't have, menu items that point at nothing in the file,
     * terms and featured pictures the file doesn't have, and a file in more
     * than one language, whose items come across in the site's default language.
     *
     * @param  list<ImportItem>  $items
     * @param  list<string>  $languages
     * @param  array{content: array<string, true>, term: array<string, true>, media: array<string, true>, menu: array<string, true>, comment?: array<string, true>, menuItem?: array<string, true>}|null  $presence  the file's IDs by kind when notes run after every page is kept
     *
     * @return array<string, array{count: int, examples: list<string>, sentence: string}>
     */
    private function notesOf(array $items, array $languages, ?array $presence = null): array
    {
        $posts = $presence['content'] ?? [];
        $menus = $presence['menu'] ?? [];
        $terms = $presence['term'] ?? [];
        $media = $presence['media'] ?? [];

        if ($presence === null) {
            foreach ($items as $item) {
                if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true)) {
                    $posts[$item->id] = true;
                } elseif ($item->kind === ImportKind::MENU) {
                    $menus[$item->id] = true;
                } elseif ($item->kind === ImportKind::TERM) {
                    $terms[$item->id] = true;
                } elseif ($item->kind === ImportKind::MEDIA) {
                    $media[$item->id] = true;
                }
            }
        }

        $notes = [];
        $comments = array_values(array_filter($items, fn (ImportItem $item): bool => $item->kind === ImportKind::COMMENT && ! isset($posts[(string) $item->postId])));
        $menuItems = array_values(array_filter($items, fn (ImportItem $item): bool => $item->kind === ImportKind::MENU_ITEM && ! isset($menus[(string) $item->menuId])));

        if ($comments !== []) {
            $notes['comment'] = ['count' => count($comments), 'examples' => array_map(fn (ImportItem $item): string => $item->id, array_slice($comments, 0, 5)), 'sentence' => 'Comments on posts that aren’t in this file weren’t brought.'];
        }

        if ($menuItems !== []) {
            $notes['menu-item'] = ['count' => count($menuItems), 'examples' => array_map(fn (ImportItem $item): string => $item->id, array_slice($menuItems, 0, 5)), 'sentence' => 'Menu items in menus that aren’t in this file weren’t brought.'];
        }

        $withoutTerm = [];
        $withoutPicture = [];
        $withoutTarget = [];

        foreach ($items as $item) {
            if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true)) {
                foreach ($item->termIds as $termId) {
                    if (! isset($terms[$termId])) {
                        $withoutTerm[] = $item->id;

                        break;
                    }
                }

                if ($item->featuredMediaId !== null && ! isset($media[$item->featuredMediaId])) {
                    $withoutPicture[] = $item->id;
                }
            } elseif ($item->kind === ImportKind::MENU_ITEM && isset($menus[(string) $item->menuId])) {
                $known = $item->itemType === ImportMenuItemType::CONTENT ? $posts : $terms;

                if (in_array($item->itemType, [ImportMenuItemType::CONTENT, ImportMenuItemType::TERM], true) && ! isset($known[(string) $item->objectId])) {
                    $withoutTarget[] = $item->id;
                }
            }
        }

        foreach ([
            'term' => [$withoutTerm, 'Terms of posts and pages that aren’t in this file weren’t brought, so those posts aren’t filed under them.'],
            'featured-image' => [$withoutPicture, 'Featured pictures that aren’t in this file weren’t brought, so those posts have none for now.'],
            'menu-target' => [$withoutTarget, 'Menu items that point at a post, page or term that isn’t in this file were brought with nothing to point at.'],
        ] as $kind => [$ids, $sentence]) {
            if ($ids !== []) {
                $notes[$kind] = ['count' => count($ids), 'examples' => array_slice($ids, 0, 5), 'sentence' => $sentence];
            }
        }

        $orphans = $this->withoutParent($items, $posts, $terms, $menus);

        if ($orphans !== []) {
            $notes['parent'] = ['count' => count($orphans), 'examples' => array_slice($orphans, 0, 5), 'sentence' => 'Items whose parent isn’t in this file were placed at the top, with no parent.'];
        }

        if (count(array_unique($languages)) > 1) {
            $notes['language'] = ['count' => 1, 'examples' => [], 'sentence' => 'This file has more than one language, and its items come across in the site’s default language.'];
        }

        return $notes;
    }

    /**
     * The IDs, in the file's order, of the items that name a parent the file doesn't have: a post, page or
     * picture whose parent isn't a post or page of the file, a term or comment or menu item whose parent
     * isn't one of its own kind. A comment on a post, or a menu item in a menu, the file doesn't have is
     * already noted, so it isn't counted here.
     *
     * @param  list<ImportItem>  $items
     * @param  array<string, true>  $posts  the source IDs of the file's posts and pages
     * @param  array<string, true>  $terms  the source IDs of the file's terms
     * @param  array<string, true>  $menus  the source IDs of the file's menus
     *
     * @return list<string>
     */
    private function withoutParent(array $items, array $posts, array $terms, array $menus): array
    {
        $own = [];

        foreach ($items as $item) {
            $own[$item->kind][$item->id] = true;
        }

        $missing = [];

        foreach ($items as $item) {
            if ($item->parentId === null) {
                continue;
            }

            $known = match ($item->kind) {
                ImportKind::POST, ImportKind::PAGE, ImportKind::MEDIA => isset($posts[$item->parentId]),
                ImportKind::TERM => isset($terms[$item->parentId]),
                ImportKind::COMMENT => ! isset($posts[(string) $item->postId]) || isset($own[ImportKind::COMMENT][$item->parentId]),
                ImportKind::MENU_ITEM => ! isset($menus[(string) $item->menuId]) || isset($own[ImportKind::MENU_ITEM][$item->parentId]),
                default => true,
            };

            if (! $known) {
                $missing[] = $item->id;
            }
        }

        return $missing;
    }

    /**
     * The sentence that refuses a file for a word a reader gave that Teme
     * doesn't know, or null when every word is one Teme knows.
     *
     * @param  list<ImportItem>  $items
     */
    private function unknownWord(array $items): ?string
    {
        foreach ($items as $item) {
            if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true) && ! ReaderRows::postStatusKnown($item->status)) {
                return 'A post or page of this file has a status Teme doesn’t know.';
            }

            if ($item->kind === ImportKind::COMMENT && ! ReaderRows::commentStatusKnown($item->status)) {
                return 'A comment of this file has a status Teme doesn’t know.';
            }

            if ($item->kind === ImportKind::TERM && ! $this->taxonomyKnown((string) ($item->fields['taxonomy'] ?? 'category'))) {
                return 'A term of this file has a taxonomy Teme doesn’t know.';
            }

            if ($item->kind === ImportKind::MENU_ITEM && ! in_array($item->itemType, ImportMenuItemType::all(), true)) {
                return 'A menu item of this file has a type Teme doesn’t know.';
            }
        }

        return null;
    }

    /**
     * Whether a reader may give a term this taxonomy: categories and tags, or a
     * taxonomy Teme has registered that isn't one Teme keeps for itself, such
     * as navigation menus or languages.
     */
    private function taxonomyKnown(string $taxonomy): bool
    {
        if ($taxonomy === ImportTaxonomy::CATEGORY || $taxonomy === ImportTaxonomy::TAG) {
            return true;
        }

        return ImportTaxonomy::valid($taxonomy)
            && ! in_array($taxonomy, TermRows::INTERNAL, true)
            && app(TaxonomyRegistry::class)->has($taxonomy);
    }

    private function refuseReader(string $sentence): void
    {
        app(RefuseImportFile::class)->execute(new RefuseImportFileInput($this->fileId, $sentence, $this->actor->userId));
    }

    /**
     * Keeps the items from $from up to $to; false when another read has moved the file on.
     *
     * @param  list<array{list: string, id: int, payload: array<string, mixed>}>  $rows
     * @param  array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}  $counted
     * @param  array{siteAddress: string|null, version: string, madeAt: string|null}  $channel
     */
    private function keep(int $from, int $to, array $rows, array $counted, array $channel): bool
    {
        return app(RecordImportFileRows::class)->execute(new RecordImportFileRowsInput(
            $this->fileId, $from, $to, $rows, $counted, $channel, $this->actor->userId,
        )) === true;
    }

    /**
     * The block reader: the one a test binds, or WordPress's grammar.
     */
    private function blocks(): ContentBlocks
    {
        return app()->bound(ContentBlocks::class) ? app(ContentBlocks::class) : ContentBlocks::wordPress();
    }

    /**
     * The authors and terms an earlier part gave IDs, and the lowest ID below zero given so far.
     *
     * @return array{0: array<string, int>, 1: array<string, int>, 2: int}
     */
    private function readSoFar(string $fileId): array
    {
        $authors = [];
        $terms = [];
        $rows = DB::table('import_file_rows')->where('file_id', $fileId)->where('object_id', '<', 0);

        foreach ((clone $rows)->where('list', ItemRows::USERS)->get(['object_id', DB::raw("payload->>'login' as login")]) as $row) {
            $authors[(string) $row->login] = (int) $row->object_id;
        }

        foreach ((clone $rows)->whereIn('list', [ItemRows::TERMS, ItemRows::OTHER_TERMS])->get(['object_id', DB::raw("payload->>'taxonomy' as taxonomy"), DB::raw("payload->>'slug' as slug")]) as $row) {
            $terms[TermRows::key((string) $row->taxonomy, (string) $row->slug)] = (int) $row->object_id;
        }

        return [$authors, $terms, min(0, (int) (clone $rows)->min('object_id'))];
    }

    /**
     * @return array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>, notes: array<string, array{count: int, examples: list<string>, sentence: string}>}
     */
    private static function noFacts(): array
    {
        return ['seo' => [], 'images' => ['total' => 0, 'own' => 0, 'hosts' => []], 'dropped' => [], 'notes' => []];
    }

    /**
     * @param  array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}  $counted
     * @param  array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}  $more
     *
     * @return array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}
     */
    private static function added(array $counted, array $more): array
    {
        foreach ($more['images']['hosts'] as $host => $count) {
            $counted['images']['hosts'][$host] = ($counted['images']['hosts'][$host] ?? 0) + $count;
        }

        return [
            'seo' => [...$counted['seo'], ...$more['seo']],
            'images' => ['total' => $counted['images']['total'] + $more['images']['total'], 'own' => $counted['images']['own'] + $more['images']['own'], 'hosts' => $counted['images']['hosts']],
            'dropped' => [...$counted['dropped'], ...$more['dropped']],
        ];
    }

    /**
     * Whether the reader's time is up. Asked again after each stage, since
     * each takes time: never a value to remember between calls.
     *
     * @phpstan-impure
     */
    private static function outOfTime(CarbonImmutable $deadline): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($deadline);
    }

    /**
     * How many items a reader is asked for on each page. Tests may set
     * `teme.import.reader_page_size` so a small file still spans several pages.
     */
    private static function readerPageSize(): int
    {
        $size = (int) config('teme.import.reader_page_size', self::ITEMS);

        return $size > 0 ? $size : self::ITEMS;
    }
}
