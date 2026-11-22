<?php

/**
 * Reads an uploaded WordPress export file into import file rows, a part at a
 * time, as the person who uploaded it. Dispatched when an upload finishes.
 *
 * @package Teme
 * @since   next
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
use App\Domain\Import\Operations\FinishReadingImportFile;
use App\Domain\Import\Operations\Input\FinishReadingImportFileInput;
use App\Domain\Import\Operations\Input\RecordImportFileRowsInput;
use App\Domain\Import\Operations\Input\RefuseImportFileInput;
use App\Domain\Import\Operations\RecordImportFileRows;
use App\Domain\Import\Operations\RefuseImportFile;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

            foreach ($stream->items($from) as $index => $item) {
                $late = CarbonImmutable::now()->greaterThanOrEqualTo($deadline);

                if ($at > $from && ($late || $at - $from >= self::ITEMS)) {
                    if (! $this->keep($from, $at, $rows, $counted, $facts)) {
                        return;
                    }

                    [$from, $rows, $counted] = [$at, [], self::noFacts()];

                    if ($late) {
                        dispatch(new self($this->fileId, $this->actor));

                        return;
                    }
                }

                $read = $reader->read($item);
                array_push($rows, ...$read['rows']);
                $counted = self::added($counted, $read['facts']);
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
     * @return array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}
     */
    private static function noFacts(): array
    {
        return ['seo' => [], 'images' => ['total' => 0, 'own' => 0, 'hosts' => []], 'dropped' => []];
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
