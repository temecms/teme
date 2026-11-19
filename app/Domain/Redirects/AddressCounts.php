<?php

/**
 * Where a reader's request notes a redirect it met or an address nothing
 * answered, without a write to the database (docs/studies/seo.md, §3.15):
 * one line appended to a file in the folder the page cache's processes
 * share, which RecordAddressCounts writes to the tables a minute later. A
 * sink (docs/architecture/operations.md, Telemetry sinks): nothing decides
 * anything from it, and a line that can't be written is dropped.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects;

use App\Domain\Addresses\PublicRequestPath;

final readonly class AddressCounts
{
    /** The spool's folder, inside the page cache's, which the web server, the worker and the scheduler share. */
    public const string FOLDER = 'counts';

    /** The file requests append to. */
    public const string OPEN = 'counts.open';

    /** The file the last run closed, read by the next, when no request can still be writing to it. */
    public const string CLOSED = 'counts.closed';

    /** The file a run is reading; one left behind is read by the next run first. */
    public const string READING = 'counts.reading';

    /** Past this size the open file takes no more lines, so a stopped scheduler can't fill the disk. */
    public const int MOST_BYTES = 67_108_864;

    /** A line's first field: a redirect that sent a reader on. */
    public const string HIT = 'h';

    /** A line's first field: an address nothing answered. */
    public const string NOT_FOUND = 'n';

    /**
     * The spool's folder, from the page cache's file store's.
     */
    public function folder(): string
    {
        return rtrim((string) config('page_cache.stores.file.path'), '/').'/'.self::FOLDER;
    }

    /**
     * Notes that this redirect sent a reader on.
     */
    public function hit(string $redirectId): void
    {
        $this->append(self::HIT."\t".$redirectId."\t".now()->getTimestamp());
    }

    /**
     * Notes that nothing answered this request path, with the host the
     * reader came from; a path no row could keep is left out; the ignore list is the minute's run's (I-874).
     */
    public function notFound(string $rawPath, ?string $referer): void
    {
        $path = PublicRequestPath::normalise($rawPath);

        if (NotFoundLog::unkept($path)) {
            return;
        }

        // Percent-encoded, so no tab or line break of a path can split a line.
        $this->append(self::NOT_FOUND."\t".PublicRequestPath::encode($path)."\t".(NotFoundLog::referrerHost($referer) ?? '')."\t".now()->getTimestamp());
    }

    /**
     * One line at the open file's end, whole, under an exclusive lock, so
     * two requests never interleave theirs. Never reported when it fails:
     * it would repeat on every request of a flood.
     */
    private function append(string $line): void
    {
        $folder = $this->folder();
        $open = $folder.'/'.self::OPEN;

        clearstatcache(true, $open);

        if (@filesize($open) > self::MOST_BYTES) {
            return;
        }

        if (@file_put_contents($open, $line."\n", FILE_APPEND | LOCK_EX) === false && ! is_dir($folder) && @mkdir($folder, 0775, true)) {
            @file_put_contents($open, $line."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
