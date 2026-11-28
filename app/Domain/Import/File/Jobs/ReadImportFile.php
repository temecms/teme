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
use App\Domain\Import\Operations\Input\ResolveImportReaderRowsInput;
use App\Domain\Import\Operations\RecordImportFileRows;
use App\Domain\Import\Operations\RefuseImportFile;
use App\Domain\Import\Operations\ResolveImportReaderRows;
use App\Domain\Import\Reader\ReaderRows;
use App\Domain\Import\Reader\ReaderSite;
use App\Domain\Languages\SiteLanguages;
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
     * part; later-page references are resolved by joining stored source IDs
     * to import_reader_ids once every page is kept — the reader runs once.
     * Resolve itself resumes under the deadline from a stored marker, so a
     * timed-out job never opens the file again. A refusal stops the file with
     * its sentence; any other failure is recorded as the plugin's fault and
     * stops it too.
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
        $address = ReaderSite::address((string) ($file->site_address ?: ''));
        $readerKey = (string) $file->reader;
        $channel = ['siteAddress' => $file->site_address, 'version' => $file->wxr_version, 'madeAt' => null];

        if ($state['resolving'] !== null) {
            if ($address === '' || $channel['siteAddress'] === null || $channel['siteAddress'] === '') {
                $this->refuseReader('This export doesn’t name its site, so Teme can’t tell where it came from.');

                return;
            }

            $this->resolveReader(
                $file,
                $address,
                $readerKey,
                $channel,
                $state,
                $deadline,
                is_string($state['defaultLanguage'] ?? null) ? $state['defaultLanguage'] : app(SiteLanguages::class)->defaultTag(),
            );

            return;
        }

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
        $from = $file->read_items;
        $cursor = $state['cursor'];
        $given = $state['given'];
        $languages = $state['languages'];
        $types = $state['types'];
        $taxonomies = $state['taxonomies'];
        $defaultLanguage = $probe->defaultLanguage ?? app(SiteLanguages::class)->defaultTag();
        $pagesThisPart = 0;

        do {
            try {
                $page = $reader->page($path, $cursor, self::readerPageSize());
            } catch (ImportReaderRefused $refusal) {
                $this->refuseReader($refusal->getMessage());

                return;
            } catch (Throwable $failure) {
                app(SeoPlugins::class)->fault($offer->plugin, 'import-reader', ImportReader::class, $failure);
                $this->refuseReader('The import reader couldn’t read this file.');

                return;
            }

            $next = $page->nextCursor;

            if ($next !== null && isset($given[(string) $next])) {
                $this->refuseReader('The import reader repeated a page of this file, so Teme stopped reading it.');

                return;
            }

            if ($next !== null) {
                $given[(string) $next] = true;

                if (count($given) > 64) {
                    $given = array_slice($given, -64, preserve_keys: true);
                }
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
            $known = $from === 0 ? [] : ReaderRows::knownOf($file->id, $address, $readerKey, $page->items);
            $rows = ReaderRows::build($page->items, $this->blocks(), $ids, $known);
            $to = $from + count($page->items);
            [$languages, $types, $taxonomies] = $this->gatherLanguages($page->items, $probe->languages, $languages, $types, $taxonomies);
            $bytes = 0;

            foreach ($rows as $row) {
                $bytes += strlen((string) json_encode($row['payload']));
            }

            $counted = self::noFacts();
            $counted['readerCursor'] = $next;
            $counted['readerGiven'] = array_map(strval(...), array_keys($given));
            $counted['readerLanguages'] = $languages;
            $counted['readerDefaultLanguage'] = $defaultLanguage;
            $counted['readerTypes'] = array_keys($types);
            $counted['readerTaxonomies'] = array_keys($taxonomies);
            $counted['notes'] = $this->notesOf($page->items, $from === 0 ? $languages : [], null);

            if ($next === null) {
                $counted['readerResolving'] = ['list' => null, 'object_id' => null];
            }

            if (! $this->keep($from, $to, $rows, $counted, $channel)) {
                return;
            }

            if ($next === null) {
                $this->resolveReader(
                    $file->refresh(),
                    $address,
                    $readerKey,
                    $channel,
                    [
                        'cursor' => null,
                        'given' => $given,
                        'languages' => $languages,
                        'types' => $types,
                        'taxonomies' => $taxonomies,
                        'resolving' => ['list' => null, 'object_id' => null],
                        'defaultLanguage' => $defaultLanguage,
                    ],
                    $deadline,
                    $defaultLanguage,
                );

                return;
            }

            $from = $to;
            $cursor = $next;
            $pagesThisPart++;

            if (self::outOfTime($deadline) || $pagesThisPart >= self::readerPagesPerPart() || $bytes >= self::BYTES) {
                dispatch(new self($this->fileId, $this->actor));

                return;
            }
        } while (true);
    }

    /**
     * Fills later-page references under the deadline, counting gap notes per
     * chunk, and finishes the file when every row has been visited.
     *
     * @param  array{siteAddress: string|null, version: string|null, madeAt: string|null}  $channel
     * @param  array{cursor: string|null, given: array<string, true>, languages: list<string>, types: array<string, true>, taxonomies: array<string, true>, resolving: array{list: ?string, object_id: ?int}|null, defaultLanguage?: string|null}  $state
     */
    private function resolveReader(
        ImportFile $file,
        string $address,
        string $readerKey,
        array $channel,
        array $state,
        CarbonImmutable $deadline,
        string $defaultLanguage,
    ): void {
        $resolving = $state['resolving'] ?? ['list' => null, 'object_id' => null];
        $result = app(ResolveImportReaderRows::class)->execute(new ResolveImportReaderRowsInput(
            $file->id,
            $address,
            $readerKey,
            $this->actor->userId,
            $resolving['list'],
            $resolving['object_id'],
            $deadline,
        ));

        $at = (int) $file->read_items;
        $progress = self::noFacts();
        $progress['notes'] = $result['notes'];
        $progress['readerCursor'] = null;
        $progress['readerGiven'] = array_map(strval(...), array_keys($state['given']));
        $progress['readerLanguages'] = $state['languages'];
        $progress['readerDefaultLanguage'] = $defaultLanguage;
        $progress['readerTypes'] = array_keys($state['types']);
        $progress['readerTaxonomies'] = array_keys($state['taxonomies']);

        if (! $result['done']) {
            $progress['readerResolving'] = [
                'list' => $result['afterList'],
                'object_id' => $result['afterObjectId'],
            ];

            if (! $this->keep($at, $at, [], $progress, $channel)) {
                return;
            }

            dispatch(new self($this->fileId, $this->actor));

            return;
        }

        $progress['notes'] = $this->withLanguagePrefixNote(
            $this->mergedResolveNotes($file->id, $result['notes']),
            $state['languages'],
        );
        $progress['replaceNotes'] = true;
        $progress['readerResolving'] = null;

        if (! $this->keep($at, $at, [], $progress, $channel)) {
            return;
        }

        app(FinishReadingImportFile::class)->execute(new FinishReadingImportFileInput($this->fileId, $this->actor->userId));
    }

    /**
     * Gap notes already kept from earlier resolve parts, plus this part's, so
     * a replaceNotes finish does not drop what a timed-out part already counted.
     *
     * @param  array<string, array{count: int, examples: list<string>, sentence: string}>  $fresh
     *
     * @return array<string, array{count: int, examples: list<string>, sentence: string}>
     */
    private function mergedResolveNotes(string $fileId, array $fresh): array
    {
        $stored = DB::table('import_file_rows')->where(['file_id' => $fileId, 'list' => ItemRows::FACTS, 'object_id' => 0])->value('payload');
        $facts = is_string($stored) ? json_decode($stored, true) : null;
        $notes = is_array($facts) && is_array($facts['notes'] ?? null) ? $facts['notes'] : [];
        unset($notes['language-prefix']);

        foreach ($fresh as $kind => $note) {
            $held = is_array($notes[$kind] ?? null) ? $notes[$kind] : ['count' => 0, 'examples' => [], 'sentence' => $note['sentence']];
            $notes[$kind] = [
                'count' => (int) $held['count'] + $note['count'],
                'examples' => array_slice([...$held['examples'], ...$note['examples']], 0, 5),
                'sentence' => $held['sentence'],
            ];
        }

        return $notes;
    }

    /**
     * The cursor and recent given cursors, and the languages, types and
     * taxonomies gathered so far, as the last keep stored them.
     *
     * @return array{cursor: string|null, given: array<string, true>, languages: list<string>, types: array<string, true>, taxonomies: array<string, true>, resolving: array{list: ?string, object_id: ?int}|null, defaultLanguage: string|null}
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
            } elseif (is_int($cursor) || (is_float($cursor) && $cursor == (int) $cursor)) {
                $given[(string) (int) $cursor] = true;
            }
        }

        $cursor = $facts['readerCursor'] ?? null;
        $languages = array_values(array_filter(array_map(strval(...), is_array($facts['readerLanguages'] ?? null) ? $facts['readerLanguages'] : []), fn (string $tag): bool => $tag !== ''));
        $types = [];
        $taxonomies = [];

        foreach (is_array($facts['readerTypes'] ?? null) ? $facts['readerTypes'] : [] as $type) {
            if (is_string($type) && $type !== '') {
                $types[$type] = true;
            }
        }

        foreach (is_array($facts['readerTaxonomies'] ?? null) ? $facts['readerTaxonomies'] : [] as $taxonomy) {
            if (is_string($taxonomy) && $taxonomy !== '') {
                $taxonomies[$taxonomy] = true;
            }
        }

        $resolving = null;

        if (array_key_exists('readerResolving', $facts) && is_array($facts['readerResolving'])) {
            $resolving = [
                'list' => isset($facts['readerResolving']['list']) && is_string($facts['readerResolving']['list'])
                    ? $facts['readerResolving']['list']
                    : null,
                'object_id' => isset($facts['readerResolving']['object_id']) && is_numeric($facts['readerResolving']['object_id'])
                    ? (int) $facts['readerResolving']['object_id']
                    : null,
            ];
        }

        $defaultLanguage = is_string($facts['readerDefaultLanguage'] ?? null) && $facts['readerDefaultLanguage'] !== ''
            ? (string) $facts['readerDefaultLanguage']
            : null;

        return [
            'cursor' => is_string($cursor) && $cursor !== '' ? $cursor : null,
            'given' => $given,
            'languages' => $languages,
            'types' => $types,
            'taxonomies' => $taxonomies,
            'resolving' => $resolving,
            'defaultLanguage' => $defaultLanguage,
        ];
    }

    /**
     * Languages the probe named plus tags items on this page use, and the
     * content types and taxonomies that carry a language, for the languages step.
     *
     * @param  list<ImportItem>  $items
     * @param  list<string>  $probeLanguages
     * @param  list<string>  $languages
     * @param  array<string, true>  $types
     * @param  array<string, true>  $taxonomies
     *
     * @return array{0: list<string>, 1: array<string, true>, 2: array<string, true>}
     */
    private function gatherLanguages(array $items, array $probeLanguages, array $languages, array $types, array $taxonomies): array
    {
        $tags = [];

        foreach ([...$probeLanguages, ...$languages] as $tag) {
            if ($tag !== '') {
                $tags[$tag] = true;
            }
        }

        foreach ($items as $item) {
            if ($item->language !== null && $item->language !== '') {
                $tags[$item->language] = true;

                if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true)) {
                    $types[$item->kind] = true;
                } elseif ($item->kind === ImportKind::TERM) {
                    $taxonomy = (string) ($item->fields['taxonomy'] ?? 'category');

                    if ($taxonomy !== '') {
                        $taxonomies[$taxonomy] = true;
                    }
                }
            }
        }

        return [array_keys($tags), $types, $taxonomies];
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
     * Gap notes from resolve, plus the language-prefix note naming each
     * language the site lacks and its /prefix/.
     *
     * @param  array<string, array{count: int, examples: list<string>, sentence: string}>  $notes
     * @param  list<string>  $languages
     *
     * @return array<string, array{count: int, examples: list<string>, sentence: string}>
     */
    private function withLanguagePrefixNote(array $notes, array $languages): array
    {
        foreach ($this->languagePrefixNote($languages) as $kind => $note) {
            $notes[$kind] = $note;
        }

        return $notes;
    }

    /**
     * The language-prefix note for the first page, naming each language the
     * site lacks and its /prefix/. Cross-page gaps are counted during resolve.
     *
     * @param  list<ImportItem>  $items
     * @param  list<string>  $languages
     *
     * @return array<string, array{count: int, examples: list<string>, sentence: string}>
     */
    private function notesOf(array $items, array $languages, mixed $presence = null): array
    {
        unset($items, $presence);

        return $this->languagePrefixNote($languages);
    }

    /**
     * @param  list<string>  $languages
     *
     * @return array<string, array{count: int, examples: list<string>, sentence: string}>
     */
    private function languagePrefixNote(array $languages): array
    {
        $notes = [];
        $named = array_values(array_unique(array_filter($languages, fn (string $tag): bool => $tag !== '')));
        $site = app(SiteLanguages::class);
        $offered = (array) config('languages');
        $lacking = array_values(array_filter($named, fn (string $tag): bool => $tag !== $site->defaultTag() && ! $site->has($tag)));

        if ($lacking !== []) {
            $listed = implode(', ', array_map(function (string $tag) use ($offered): string {
                $name = is_array($offered[$tag] ?? null) ? (string) ($offered[$tag]['label'] ?? $tag) : $tag;

                return $name.' (/'.$tag.'/)';
            }, $lacking));
            $notes['language-prefix'] = [
                'count' => count($lacking),
                'examples' => $lacking,
                'sentence' => 'This file brings languages the site doesn’t have yet — '.$listed.' — each added with that first part of the address.',
            ];
        }

        return $notes;
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

    /**
     * How many reader pages one job keeps. Tests may set
     * `teme.import.reader_pages_per_part` so a part ends after one page.
     */
    private static function readerPagesPerPart(): int
    {
        $pages = config('teme.import.reader_pages_per_part');

        if ($pages === null || $pages === '') {
            return PHP_INT_MAX;
        }

        $count = (int) $pages;

        return $count > 0 ? $count : PHP_INT_MAX;
    }
}
