<?php

/**
 * The media phase (I-338): each WordPress attachment's original downloaded
 * before its transaction through the safe downloader, stored once by its
 * bytes with its details, and every address of its files recorded for the
 * converter. A later run downloads again only a file WordPress changed, and
 * never takes back a person's change here.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\ImageTrace;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Input\RecordMediaFilesInput;
use App\Domain\Import\Operations\RecordMediaFiles;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\PreparesObjects;
use App\Domain\Import\Phases\RunsInMediaPass;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\Terms\WordPressText;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Uploads\SourceUploads;
use App\Domain\Media\Downloads\DownloadedFile;
use App\Domain\Media\Downloads\DownloadRefused;
use App\Domain\Media\Downloads\FileDownloader;
use App\Domain\Media\FileInspector;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\ImportMedia;
use App\Domain\Media\Operations\Input\ImportMediaInput;
use App\Domain\Media\Operations\Input\UpdateImportedMediaInput;
use App\Domain\Media\Operations\UpdateImportedMedia;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use LogicException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class MediaPhase implements ListPhase, PreparesObjects, RunsInMediaPass
{
    /** Attachments a page: a page job starts none after ImportListPage::SECONDS and leaves the rest to the next (#254). */
    public const int PAGE = 20;

    /** The most media lanes a run reads side by side (#701). */
    public const int MAX_LANES = 8;

    /** The seconds one download may take, its tries and waits included. */
    public const int SECONDS = 20;

    /** The seconds an attachment's downloads may take together, its fallback's included. */
    public const int ITEM_SECONDS = 25;

    /** The words each detail goes by in a message. */
    private const array NAMES = ['title' => 'title', 'alt' => 'alt text', 'caption' => 'caption', 'file' => 'file'];

    public function __construct(
        private ImportMap $map,
        private FileDownloader $downloader,
        private FileInspector $inspector,
        private SettingsReader $settings,
        private SourceUploads $uploads,
    ) {}

    public function key(): string
    {
        return 'media';
    }

    public function order(): int
    {
        return 400;
    }

    public function label(): string
    {
        return 'Media';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    /**
     * The media list, split into the run's lanes for an export file: lane k
     * of N reads the attachments whose ID leaves k over N, each lane a chain
     * of its own in the phase's batch (#701). A live import reads one list
     * until the companion can page by ranges; that is the one condition to
     * change then (docs/architecture/import.md). A picture pass of an
     * export file reads here only the attachments posts wait for, and
     * MediaRestPhase the rest after them (#716).
     */
    public function lists(ImportContext $context): array
    {
        $pass = $context->kind === ImportRunKind::MediaPass;

        return $this->listsOf($context, $pass && $context->readsFile() ? 'only' : null);
    }

    /**
     * The media lists, of the attachments posts wait for ('only'), of the
     * others ('without'), or of every one (null).
     *
     * @return list<ImportList>
     *
     * @since 1.5.0
     */
    public function listsOf(ImportContext $context, ?string $waited): array
    {
        // Kept on the old site, as links or until the picture pass: nothing is downloaded or recorded here (docs/architecture/import.md).
        if ($context->keepsOldSiteMedia() && $context->kind !== ImportRunKind::MediaPass) {
            return [];
        }

        $query = $context->modifiedAfter === null ? [] : ['modified_after' => $context->modifiedAfter->utc()->format('Y-m-d H:i:s')];
        $query = $waited === null ? $query : [...$query, 'waited' => $waited];

        $lanes = $context->readsFile() ? max(1, min(self::MAX_LANES, $context->mediaLanes)) : 1;

        if ($lanes === 1) {
            return [new ImportList('media', $query, self::PAGE)];
        }

        return array_map(
            fn (int $lane): ImportList => new ImportList('media', [...$query, 'lane' => $lane, 'lanes' => $lanes], self::PAGE),
            range(0, $lanes - 1),
        );
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return SourceRef::of('attachment', (int) $item['id']);
    }

    /**
     * The attachment's file, downloaded only when import() will store it:
     * for an attachment not here yet; and, once WordPress's file has
     * changed, for an item this import made that is still here and out of
     * the trash, or for an attachment that used another's item.
     */
    public function prepare(SourceRef $ref, array $item, ImportContext $context): PreparedObject
    {
        $wanted = self::address($item);

        if ($wanted === null || ($item['status'] ?? null) === 'trash') {
            return PreparedMedia::nothing();
        }

        $entry = $this->map->entry($context->sourceId, $ref);

        if ($entry?->teme_id !== null) {
            $media = Media::withTrashed()->find($entry->teme_id);
            $duplicate = ($entry->facts['duplicate'] ?? false) === true;
            $changed = ($entry->facts['source'] ?? null) !== $wanted;

            if (! $changed || (! $duplicate && ($media === null || $media->trashed()))) {
                return PreparedMedia::nothing();
            }
        }

        return $this->download($item, $wanted, $context);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        $prepared = $prepared instanceof PreparedMedia ? $prepared : PreparedMedia::nothing();

        if (($item['status'] ?? null) === 'trash') {
            return ImportResult::skipped(new ImportMessage('attachment.trashed', 'It was in WordPress’s trash, so it wasn’t imported.'));
        }

        if (self::address($item) === null) {
            return ImportResult::skipped(new ImportMessage('attachment.no-file', 'WordPress has no address for its file, so it wasn’t imported.'));
        }

        $entry = $this->map->entry($context->sourceId, $ref);

        if ($entry?->teme_id !== null) {
            return $this->again($ref, $entry, $item, $prepared, $context);
        }

        if ($prepared->refusal !== null) {
            return ImportResult::skipped($prepared->refusal);
        }

        return $this->store($ref, $item, $prepared, $context);
    }

    /**
     * The downloaded file as the attachment's own item: a new one, or the
     * library's item that already holds those bytes; its addresses
     * recorded.
     *
     * @param  array<string, mixed>  $item
     */
    private function store(SourceRef $ref, array $item, PreparedMedia $prepared, ImportContext $context): ImportResult
    {
        $file = $prepared->file ?? throw new LogicException('The media phase downloads the file it stores in prepare().');
        $details = self::details($item);
        $stored = app(ImportMedia::class)->execute(new ImportMediaInput(
            path: $file->path,
            filename: $file->name,
            sourceUrl: $file->sourceUrl,
            title: $details['title'],
            alt: $details['alt'],
            caption: $details['caption'],
            uploadedBy: $this->uploader($item, $context),
            uploadedAt: self::uploaded($item['date_gmt'] ?? null),
            actorId: $context->actorId(),
        ));

        $result = $stored['created']
            ? self::warned(ImportResult::created('media', $stored['mediaId']), [...$prepared->warnings, ...$details['warnings']])
            : ImportResult::updated('media', $stored['mediaId'])->withWarning(self::duplicate());

        return self::warned($result, [$this->recordFiles($ref, $item, $stored['mediaId'], $context)])
            ->withFacts([...self::facts($item), 'written' => $stored['written'], 'duplicate' => ! $stored['created'], 'picture_from' => self::source($prepared)]);
    }

    /**
     * An attachment an earlier run met: its item left as it is when it was
     * deleted or trashed here, or isn't this import's own (but for a new
     * WordPress file, which becomes its own); else brought up to date where
     * nobody changed it here.
     *
     * @param  array<string, mixed>  $item
     */
    private function again(SourceRef $ref, ImportMapEntry $entry, array $item, PreparedMedia $prepared, ImportContext $context): ImportResult
    {
        $mediaId = (string) $entry->teme_id;
        $media = Media::withTrashed()->find($mediaId);
        $written = $entry->facts['written'] ?? null;
        $duplicate = ($entry->facts['duplicate'] ?? false) === true || ! is_array($written);

        // WordPress changed the file of an attachment that used another's item: the new file becomes its own, and that item stays as it was.
        if ($duplicate && $prepared->file !== null) {
            return $this->store($ref, $item, $prepared, $context);
        }

        if ($media === null) {
            return ImportResult::skipped(new ImportMessage('attachment.deleted-here', 'It was imported before and has since been deleted in Teme, so it wasn’t imported again.'));
        }

        $files = $this->recordFiles($ref, $item, $mediaId, $context);

        if ($media->trashed()) {
            $result = ImportResult::updated('media', $mediaId)->withWarning(new ImportMessage('attachment.trashed-here', 'It’s in Teme’s trash, so the import left it there as it was.'));

            return self::warned($result, [$files])->withFacts(self::facts($item));
        }

        if ($duplicate) {
            return self::warned(ImportResult::updated('media', $mediaId)->withWarning(self::duplicate()), [$files, $prepared->refusal === null ? null : self::fileKept()])
                ->withFacts([...self::facts($item), 'duplicate' => true]);
        }

        $details = self::details($item);
        $update = app(UpdateImportedMedia::class)->execute(new UpdateImportedMediaInput(
            mediaId: $mediaId,
            written: $written,
            title: $details['title'],
            alt: $details['alt'],
            caption: $details['caption'],
            path: $prepared->file?->path,
            filename: $prepared->file?->name,
            sourceUrl: $prepared->file?->sourceUrl,
            actorId: $context->actorId(),
        ));

        $said = [...$prepared->warnings, ...$details['warnings'], $files];

        if ($update['kept'] !== []) {
            $said[] = self::kept($update['kept']);
        }

        // A new file that can't come for good, or that is another kind than the item's, leaves the item's file, said rather than passed over.
        if ($prepared->refusal !== null || $update['refused']) {
            $said[] = self::fileKept();
        }

        return self::warned(ImportResult::updated('media', $mediaId), $said)
            ->withFacts([...self::facts($item), 'written' => $update['written'], 'duplicate' => false, 'picture_from' => self::source($prepared)]);
    }

    /**
     * Downloads the original, else WordPress's main file when the original
     * can't come for good; the second download gets what is left of the
     * attachment's 25 seconds, at least 5.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws ImportFailed when trying again later may bring it
     */
    private function download(array $item, string $wanted, ImportContext $context): PreparedMedia
    {
        // Every candidate in the uploads copy is tried before the site is asked for anything (#791).
        $copied = $this->fromCopy($item, $wanted, $context);

        if ($copied !== null) {
            return PreparedMedia::file($copied, [], true);
        }

        $started = (int) hrtime(true);

        try {
            return PreparedMedia::file($this->fetch($wanted, self::SECONDS));
        } catch (DownloadRefused|ValidationException $refused) {
            $main = is_string($item['url'] ?? null) ? trim($item['url']) : '';

            if (($refused instanceof DownloadRefused && $refused->retryable) || $main === $wanted || preg_match('#^https?://#i', $main) !== 1) {
                return self::refusal($refused);
            }

            return $this->fallback($main, rtrim(self::reason($refused), '.'), $started, $context);
        }
    }

    /**
     * The attachment's picture from the site's uploads copy, judged as the media
     * library will judge it: its original first, then its main file, each by the
     * path its own file names, whatever host serves it; null when the copy lacks
     * both, or holds neither as a picture Teme will take.
     *
     * @param  array<string, mixed>  $item
     */
    private function fromCopy(array $item, string $wanted, ImportContext $context): ?DownloadedFile
    {
        $maxBytes = $this->settings->int('media', 'max_upload_mb') * 1024 * 1024;
        $paths = self::copyPaths($item);

        if ($paths === []) {
            // No file of its own: the copy answers only for an address on the site.
            $copied = $this->uploads->download($context->sourceId, $wanted, $maxBytes);

            return $this->judged($copied);
        }

        foreach ($paths as $relative) {
            $copied = $this->uploads->downloadFile($context->sourceId, $relative, $wanted, $maxBytes);
            $judged = $this->judged($copied);

            if ($judged !== null) {
                return $judged;
            }
        }

        return null;
    }

    /**
     * A copied file that Teme will take, counted for the report; null when it
     * won't do, its file released.
     */
    private function judged(?DownloadedFile $copied): ?DownloadedFile
    {
        if ($copied === null) {
            return null;
        }

        try {
            $this->judge($copied);
        } catch (ValidationException) {
            return null;
        }

        return $copied;
    }

    /**
     * Where a stored picture's file came from, for the run's counts: the site's uploads copy, or the old site.
     */
    private static function source(PreparedMedia $prepared): string
    {
        return $prepared->fromCopy ? 'copy' : 'site';
    }

    /**
     * The paths under the uploads folder an attachment's files are named by: its
     * original's, then its main file's. Empty for an attachment with no file.
     *
     * @param  array<string, mixed>  $item
     *
     * @return list<string>
     */
    private static function copyPaths(array $item): array
    {
        $main = WordPressFiles::relative((string) ($item['file'] ?? ''));

        if ($main === null) {
            return [];
        }

        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $paths = [];

        if (is_string($metadata['original_image'] ?? null) && $metadata['original_image'] !== '') {
            $folder = str_contains($main, '/') ? substr($main, 0, (int) strrpos($main, '/') + 1) : '';
            $paths[] = $folder.basename($metadata['original_image']);
        }

        $paths[] = $main;

        return array_values(array_unique($paths));
    }

    /**
     * WordPress's main file, made for the web, when the original can't come
     * for good, in what is left of the attachment's seconds.
     *
     * @throws ImportFailed when trying again later may bring it
     */
    private function fallback(string $main, string $why, int $started, ImportContext $context): PreparedMedia
    {
        $left = self::ITEM_SECONDS - intdiv((int) hrtime(true) - $started, 1_000_000_000);

        try {
            $file = $this->fetch($main, max(5, $left));
        } catch (DownloadRefused|ValidationException $refused) {
            return self::refusal($refused);
        }

        return PreparedMedia::file($file, [new ImportMessage('attachment.scaled-copy', "WordPress’s original of this image couldn’t come across ({$why}), so Teme brought the copy WordPress made for the web.")]);
    }

    /**
     * The file at this address, downloaded and judged as the media library
     * will judge it: its type, its size and its pixels, from its bytes.
     *
     * @throws DownloadRefused
     * @throws ValidationException
     */
    private function fetch(string $address, int $seconds): DownloadedFile
    {
        $file = $this->downloader->download($address, seconds: $seconds);
        $this->judge($file);

        return $file;
    }

    /**
     * Judges a downloaded file as the media library will: its type, its size and its pixels, from its bytes. Releases it and throws when it won't do.
     *
     * @throws ValidationException
     */
    private function judge(DownloadedFile $file): void
    {
        try {
            $this->inspector->inspect(
                $file->path,
                $file->name,
                $this->settings->int('media', 'max_upload_mb') * 1024 * 1024,
                $this->settings->int('media', 'max_megapixels') * 1_000_000,
            );
        } catch (ValidationException $refused) {
            $file->release();

            throw $refused;
        }
    }

    /**
     * Records the attachment's file addresses; what to say when another
     * attachment holds one, else null.
     *
     * @param  array<string, mixed>  $item
     */
    private function recordFiles(SourceRef $ref, array $item, string $mediaId, ImportContext $context): ?ImportMessage
    {
        $taken = app(RecordMediaFiles::class)->execute(new RecordMediaFilesInput($context->sourceId, (int) $ref->id, WordPressFiles::keys($item), $mediaId, $context->actorId()));

        return $taken === []
            ? null
            : new ImportMessage('attachment.file-shared', "Another attachment already has the file address {$taken[0]}, so an image at that address is that attachment’s here.", ['files' => count($taken)]);
    }

    /**
     * The person WordPress's author became here, or null.
     *
     * @param  array<string, mixed>  $item
     */
    private function uploader(array $item, ImportContext $context): ?string
    {
        $author = (int) ($item['author'] ?? 0);
        $id = $author > 0 ? $this->map->temeId($context->sourceId, SourceRef::of('user', $author)) : null;

        return $id !== null && User::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * A refusal for good, as the skipped attachment's reason.
     *
     * @throws ImportFailed for one trying again later may mend
     */
    private static function refusal(DownloadRefused|ValidationException $refused): PreparedMedia
    {
        $reason = self::reason($refused);

        if ($refused instanceof DownloadRefused && $refused->retryable) {
            throw new ImportFailed(ImportMessage::error('attachment.download-failed', "Its file couldn’t be downloaded this time: {$reason} A full import tries it again.", ['reason' => $refused->reason]));
        }

        return PreparedMedia::refused(new ImportMessage(
            'attachment.refused',
            "Its file couldn’t come across: {$reason} It wasn’t imported; add the file in Media yourself if you need it.",
            ['reason' => $refused instanceof DownloadRefused ? $refused->reason : 'library'],
        ));
    }

    private static function reason(DownloadRefused|ValidationException $refused): string
    {
        return $refused instanceof DownloadRefused
            ? $refused->sentence()
            : (string) (Arr::first(Arr::flatten($refused->errors())) ?? 'The media library refused it.');
    }

    /**
     * The address to download: the original WordPress keeps behind a
     * -scaled or -rotated copy while it is the main file's own (an edit in
     * WordPress moves the main file but leaves original_image naming the
     * unedited upload); else the main file. Null without an http or https
     * one.
     *
     * @param  array<string, mixed>  $item
     */
    private static function address(array $item): ?string
    {
        $main = self::web($item['url'] ?? null);
        $original = self::web($item['original_url'] ?? null);
        $file = WordPressFiles::relative((string) ($item['file'] ?? ''));
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $kept = is_string($metadata['original_image'] ?? null) ? basename($metadata['original_image']) : '';

        if ($original !== null && $file !== null && $kept !== '') {
            $folder = str_contains($file, '/') ? substr($file, 0, (int) strrpos($file, '/') + 1) : '';

            if (ImageTrace::original(ConversionSite::fileKey($file)) === ConversionSite::fileKey($folder.$kept)) {
                return $original;
            }
        }

        return $main;
    }

    /**
     * The address when it is an http or https one, else null.
     */
    private static function web(mixed $address): ?string
    {
        return is_string($address) && preg_match('#^https?://#i', trim($address)) === 1 ? trim($address) : null;
    }

    /**
     * What the map keeps of the attachment.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{parent: int, page_path: string|null, source: string|null}
     */
    private static function facts(array $item): array
    {
        return ['parent' => max(0, (int) ($item['parent'] ?? 0)), 'page_path' => self::page($item['attachment_page'] ?? null), 'source' => self::address($item)];
    }

    /**
     * The attachment page's path in the path table's form, or null for a
     * page with a query (WordPress's ?attachment_id=, I-910), or none.
     */
    private static function page(mixed $address): ?string
    {
        $parts = is_string($address) ? parse_url(trim($address)) : false;

        if (! is_array($parts) || isset($parts['query']) || ! isset($parts['path'])) {
            return null;
        }

        $path = PublicRequestPath::normalise($parts['path']);

        return $path === '' ? null : $path;
    }

    /**
     * WordPress's title, alt text and caption as the library keeps them,
     * each cut to its limit, and what to say of them.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{title: string, alt: string|null, caption: string|null, warnings: list<ImportMessage>}
     */
    private static function details(array $item): array
    {
        $warnings = [];
        $title = WordPressText::decoded($item['title'] ?? '');

        if ($title === '') {
            $named = pathinfo(rawurldecode(basename((string) parse_url((string) self::address($item), PHP_URL_PATH))), PATHINFO_FILENAME);
            $title = trim($named) === '' ? 'Imported file' : trim($named);
        }

        $alt = WordPressText::decoded($item['alt'] ?? '');
        $caption = WordPressText::plain($item['caption'] ?? null);
        $description = WordPressText::plain($item['description'] ?? null);

        [$title, $warnings] = self::cut($title, 255, 'attachment.title-shortened', 'Its title was longer than Teme’s 255 characters, so it was cut short.', $warnings);
        [$alt, $warnings] = self::cut($alt, 1000, 'attachment.alt-shortened', 'Its alt text was longer than Teme’s 1,000 characters, so it was cut short.', $warnings);
        [$caption, $warnings] = self::cut((string) $caption, 2000, 'attachment.caption-shortened', 'Its caption was longer than Teme’s 2,000 characters, so it was cut short.', $warnings);

        if ($description !== null && ! in_array($description, [$title, $alt, $caption], true)) {
            $warnings[] = new ImportMessage('attachment.description-not-kept', 'Its description isn’t kept: WordPress showed it only on the attachment’s own page, which Teme sends on to the post or the file.');
        }

        return ['title' => $title, 'alt' => $alt === '' ? null : $alt, 'caption' => $caption === '' ? null : $caption, 'warnings' => $warnings];
    }

    /**
     * The text cut to $limit characters, and the warnings with this one
     * added when it was.
     *
     * @param  list<ImportMessage>  $warnings
     *
     * @return array{0: string, 1: list<ImportMessage>}
     */
    private static function cut(string $text, int $limit, string $code, string $words, array $warnings): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text, $warnings];
        }

        return [rtrim(mb_substr($text, 0, $limit)), [...$warnings, new ImportMessage($code, $words)]];
    }

    /**
     * When WordPress says it was uploaded: date_gmt, in UTC; null for a
     * missing or zero date.
     */
    private static function uploaded(mixed $value): ?CarbonImmutable
    {
        $text = (string) $value;

        if (preg_match('/^(19[7-9][0-9]|2[0-9]{3})-[01][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]$/', $text) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $text, 'UTC') ?: null;
    }

    private static function duplicate(): ImportMessage
    {
        return new ImportMessage('attachment.duplicate', 'The media library already had this file, so this attachment uses that item, whose details stay as they were.');
    }

    private static function fileKept(): ImportMessage
    {
        return new ImportMessage('attachment.file-kept', 'WordPress’s new file for it couldn’t come across, so the item keeps the file it had.');
    }

    /**
     * What a person changed here and the import kept.
     *
     * @param  list<string>  $fields
     */
    private static function kept(array $fields): ImportMessage
    {
        $names = array_map(fn (string $field): string => self::NAMES[$field] ?? $field, $fields);
        $last = array_pop($names);
        $listed = $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
        $them = count($fields) === 1 ? 'it' : 'them';

        return new ImportMessage('attachment.kept-here', "Its {$listed} changed in Teme since the last import, so Teme kept {$them} rather than WordPress’s.", ['fields' => $fields]);
    }

    /**
     * The result with these warnings added, the nulls left out.
     *
     * @param  list<ImportMessage|null>  $warnings
     */
    private static function warned(ImportResult $result, array $warnings): ImportResult
    {
        foreach ($warnings as $warning) {
            if ($warning !== null) {
                $result = $result->withWarning($warning);
            }
        }

        return $result;
    }
}
