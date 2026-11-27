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

    /**
     * The largest file an import reader reads: a reader's whole file is read in one
     * job, under the deadline, so a larger one is refused when its upload starts.
     */
    public const int READER_BYTES = 64 * 1024 * 1024;

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
     * Reads a file a plugin's import reader reads: the whole file, then its
     * rows in one record. A refusal stops the file with its sentence; any
     * other failure is recorded as the plugin's fault and stops it too.
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

        try {
            $reader = $readers->reader($offer);
            $probe = $reader->probe($path);
            $items = [];
            $cursor = null;
            $given = [];

            do {
                $page = $reader->page($path, $cursor, self::ITEMS);
                array_push($items, ...$page->items);
                $cursor = $page->nextCursor;

                if ($cursor !== null && isset($given[$cursor])) {
                    $this->refuseReader('The import reader repeated a page of this file, so Teme stopped reading it.');

                    return;
                }

                if ($cursor !== null) {
                    $given[$cursor] = true;
                }

                if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                    $this->refuseReader('This file took too long to read in one go, so Teme couldn’t finish reading it.');

                    return;
                }
            } while ($cursor !== null);
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

        $unknown = $this->unknownWord($items);

        if ($unknown !== null) {
            $this->refuseReader($unknown);

            return;
        }

        $ids = app(AssignImportReaderIds::class)->execute(new AssignImportReaderIdsInput(
            ReaderSite::address((string) $probe->siteAddress),
            (string) $file->reader,
            array_map(fn (ImportItem $item): array => ['kind' => $item->kind, 'id' => $item->id], $items),
            $this->actor->userId,
        ));

        if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
            $this->refuseReader('This file took too long to read in one go, so Teme couldn’t finish reading it.');

            return;
        }

        $rows = ReaderRows::build($items, $this->blocks(), $ids);

        if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
            $this->refuseReader('This file took too long to read in one go, so Teme couldn’t finish reading it.');

            return;
        }
        $facts = ['siteAddress' => $probe->siteAddress, 'version' => $probe->version, 'madeAt' => null];
        $counted = self::noFacts();
        $counted['notes'] = $this->notesOf($items, $probe->languages);

        if (! $this->keep(0, count($items), $rows, $counted, $facts)) {
            return;
        }

        app(FinishReadingImportFile::class)->execute(new FinishReadingImportFileInput($this->fileId, $this->actor->userId));
    }

    /**
     * What a reader's file holds that Teme doesn't bring, counted and named
     * for the report: comments on posts the file doesn't have, menu items in
     * menus it doesn't have, and a file in more than one language, whose items
     * come across in the site's default language.
     *
     * @param  list<ImportItem>  $items
     * @param  list<string>  $languages
     *
     * @return array<string, array{count: int, examples: list<string>, sentence: string}>
     */
    private function notesOf(array $items, array $languages): array
    {
        $posts = [];
        $menus = [];

        foreach ($items as $item) {
            if (in_array($item->kind, [ImportKind::POST, ImportKind::PAGE], true)) {
                $posts[$item->id] = true;
            } elseif ($item->kind === ImportKind::MENU) {
                $menus[$item->id] = true;
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

        if (count(array_unique($languages)) > 1) {
            $notes['language'] = ['count' => 1, 'examples' => [], 'sentence' => 'This file has more than one language, and its items come across in the site’s default language.'];
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
}
