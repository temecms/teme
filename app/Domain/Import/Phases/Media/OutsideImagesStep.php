<?php

/**
 * Images brought into the library before posts convert (I-1703): each
 * <img> on a host the person ticked, and each of the old site's own that
 * the media list didn't bring when downloads are wanted. Each address is
 * downloaded once through the safe downloader, stored by its bytes and
 * recorded in the map. A job spends at most fifty seconds, then carries
 * on at the last item it finished.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Media\ImageTrace;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Operations\Input\RecordOutsideImageInput;
use App\Domain\Import\Operations\RecordOutsideImage;
use App\Domain\Import\Phases\Content\ContentSite;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\RunsInMediaPass;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Uploads\SourceUploads;
use App\Domain\Media\Downloads\DownloadedFile;
use App\Domain\Media\Downloads\DownloadRefused;
use App\Domain\Media\Downloads\FileDownloader;
use App\Domain\Media\Operations\ImportMedia;
use App\Domain\Media\Operations\Input\ImportMediaInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class OutsideImagesStep implements RunsInMediaPass, StepPhase
{
    /** Content items a read. */
    public const int PAGE = 50;

    /** The seconds a job spends, its read included: under the queue's 75 a job. */
    public const int BUDGET = 50;

    /** The most seconds one download may take. */
    public const int SECONDS = 20;

    public function __construct(
        private ImportMap $map,
        private FileDownloader $downloader,
        private ContentSite $sites,
        private SourceUploads $uploads,
        private SettingsReader $settings,
    ) {}

    public function key(): string
    {
        return 'outside-images';
    }

    public function order(): int
    {
        return 450;
    }

    public function label(): string
    {
        return 'Images from other sites';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $hosts = self::hosts($context->choice('outside_images', []));
        // The old site's own images come with its media files: never as links, and in the picture pass when that brings them (#716).
        $own = ! $context->keepsOldSiteMedia() || $context->kind === ImportRunKind::MediaPass;

        if ($hosts === [] && ! $own) {
            return StepResult::done();
        }

        $site = $this->sites->for($context->sourceId);
        $media = new ImportedMedia($context->sourceId);
        $started = CarbonImmutable::now();
        $after = max(0, (int) $cursor);
        $query = $context->modifiedAfter === null ? [] : ['modified_after' => $context->modifiedAfter->utc()->format('Y-m-d H:i:s')];
        $page = $reader->page('content', $query, $after, self::PAGE);
        $messages = [];
        $downloaded = 0;
        $done = $after;

        foreach ($page->items as $item) {
            // Said once the item is finished: a job that stops inside it leaves them to the job that finishes it.
            $unkeyed = [];

            foreach (isset($item['error']) ? [] : $this->images($item, $hosts, $site, $media, $own) as $image) {
                if ($image['key'] === null) {
                    $unkeyed[] = is_string(parse_url($image['address'], PHP_URL_USER))
                        ? new ImportMessage('outside-image.credentials', "An image address from {$image['host']} carries a user name, so Teme won’t download it, and posts link to it instead.", ['host' => $image['host']])
                        : new ImportMessage('outside-image.too-long', "An image address from {$image['host']} is too long for Teme to keep, so posts link to it instead.", ['host' => $image['host']]);

                    continue;
                }

                if ($this->settled($image['key'], $context)) {
                    continue;
                }

                $spent = $started->diffInSeconds(CarbonImmutable::now(), true);

                // At least one download a job, so every job moves on; none once the budget is spent.
                if ($downloaded > 0 && $spent >= self::BUDGET - 5) {
                    return StepResult::continueAt((string) $done, $messages);
                }

                $downloaded++;
                $said = $this->bring($image, $context, max(5, min(self::SECONDS, (int) floor(self::BUDGET - $spent))));

                if ($said !== null) {
                    $messages[] = $said;
                }
            }

            $messages = [...$messages, ...$unkeyed];
            $done = max($done, (int) ($item['id'] ?? 0));
        }

        return $page->nextAfterId === null
            ? StepResult::done($messages)
            : StepResult::continueAt((string) $page->nextAfterId, $messages);
    }

    /**
     * Whether an address needs nothing more this run: brought in before (an
     * item deleted here since stays deleted), refused for good, or failed
     * already in this run.
     */
    private function settled(string $key, ImportContext $context): bool
    {
        $entry = $this->map->entry($context->sourceId, new SourceRef('outside-image', $key));

        return $entry !== null && (
            $entry->teme_id !== null
            || $entry->status === ImportStatus::Skipped
            || ($entry->status === ImportStatus::Failed && $entry->run_id === $context->runId)
        );
    }

    /**
     * A picture from the old site's uploads copy when it has one, and it is an image; else null, and the site is asked.
     */
    private function fromCopy(string $address, ImportContext $context): ?DownloadedFile
    {
        $copied = $this->uploads->download($context->sourceId, $address, $this->settings->int('media', 'max_upload_mb') * 1024 * 1024);

        if ($copied === null || $copied->kind !== 'image') {
            $copied?->release();

            return null;
        }

        return $copied;
    }

    /**
     * One image downloaded, stored by its bytes and recorded; what to say
     * when it couldn't come, else null. The download is deleted whatever
     * happened.
     *
     * @param  array{address: string, key: string|null, host: string, alt: string|null}  $image
     */
    private function bring(array $image, ImportContext $context, int $seconds): ?ImportMessage
    {
        $key = (string) $image['key'];
        $file = basename(rawurldecode((string) parse_url($image['address'], PHP_URL_PATH)));

        try {
            $copied = $this->fromCopy($image['address'], $context);
            $download = $copied ?? $this->downloader->download($image['address'], imagesOnly: true, seconds: $seconds);
        } catch (DownloadRefused $refused) {
            return $this->refused($refused->retryable, $refused->sentence(), $key, $image['host'], $file, $context);
        }

        try {
            $stored = app(ImportMedia::class)->execute(new ImportMediaInput(
                path: $download->path,
                filename: $download->name,
                sourceUrl: $download->sourceUrl,
                title: self::title($download->name),
                alt: $image['alt'],
                caption: null,
                uploadedBy: null,
                uploadedAt: null,
                actorId: $context->actorId(),
            ));
        } catch (ValidationException $refused) {
            return $this->refused(false, (string) (Arr::first(Arr::flatten($refused->errors())) ?? 'The media library refused it.'), $key, $image['host'], $file, $context);
        } finally {
            $download->release();
        }

        app(RecordOutsideImage::class)->execute(new RecordOutsideImageInput(
            $context->sourceId,
            $context->runId,
            $key,
            $stored['created'] ? ImportOutcome::Created : ImportOutcome::Updated,
            $stored['mediaId'],
            $image['host'],
            $context->actorId(),
            $copied === null ? 'site' : 'copy',
        ));

        return null;
    }

    /**
     * Records an image that didn't come, failed when trying again may
     * bring it, skipped when it can't; and says so.
     */
    private function refused(bool $again, string $reason, string $key, string $host, string $file, ImportContext $context): ImportMessage
    {
        app(RecordOutsideImage::class)->execute(new RecordOutsideImageInput(
            $context->sourceId,
            $context->runId,
            $key,
            $again ? ImportOutcome::Failed : ImportOutcome::Skipped,
            null,
            $host,
            $context->actorId(),
        ));

        return $again
            ? ImportMessage::error('outside-image.failed', "An image from {$host} ({$file}) couldn’t be downloaded this time: {$reason} A full import tries it again.", ['host' => $host])
            : new ImportMessage('outside-image.refused', "An image from {$host} ({$file}) couldn’t come across: {$reason} Posts link to it instead.", ['host' => $host]);
    }

    /**
     * The images an item's content shows that this step should bring: each
     * on a ticked host, and each of the old site's own that Teme has no
     * media for yet when downloads are wanted, each address once.
     *
     * @param  array<int|string, mixed>  $item
     * @param  list<string>  $hosts
     *
     * @return list<array{address: string, key: string|null, host: string, alt: string|null}>
     */
    private function images(array $item, array $hosts, ConversionSite $site, ImportedMedia $media, bool $own): array
    {
        $html = (string) ($item['content'] ?? '');

        if (stripos($html, '<img') === false) {
            return [];
        }

        $found = [];

        foreach (iterator_to_array(HtmlFragment::body($html)->querySelectorAll('img')) as $img) {
            $src = trim(ImageTrace::source($img));
            $address = $site->absolute($src);

            if ($address === null || preg_match('#^https?://#i', $address) !== 1) {
                continue;
            }

            $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($address, PHP_URL_HOST)));
            $ticked = in_array($host, $hosts, true);

            if (! $ticked) {
                if (! $own) {
                    continue;
                }

                $traced = ImageTrace::find($img, $site, $media);

                if (! $traced['own'] || $traced['mediaId'] !== null) {
                    continue;
                }
            }

            $key = ImportedMedia::outsideKey($address);
            $alt = trim((string) $img->getAttribute('alt'));
            $alt = $alt === '' ? null : mb_substr($alt, 0, 1000);
            $at = $key ?? $address;

            if (! isset($found[$at])) {
                $found[$at] = ['address' => $address, 'key' => $key, 'host' => $host, 'alt' => $alt];
            } elseif ($found[$at]['alt'] === null) {
                $found[$at]['alt'] = $alt;
            }
        }

        return array_values($found);
    }

    /**
     * The ticked hosts, lower-cased, without www.
     *
     * @return list<string>
     */
    private static function hosts(mixed $choice): array
    {
        $hosts = [];

        foreach (is_array($choice) ? $choice : [] as $host) {
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = (string) preg_replace('/^www\./', '', strtolower(trim($host)));
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * A downloaded image's title: its file's name, without its extension.
     */
    private static function title(string $name): string
    {
        $title = trim(pathinfo($name, PATHINFO_FILENAME));

        return $title === '' ? 'Imported file' : mb_substr($title, 0, 255);
    }
}
