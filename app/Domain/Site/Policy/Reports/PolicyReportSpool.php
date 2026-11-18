<?php

/**
 * Where a browser's policy report lands without a write to the database
 * (I-3375): one line appended to a file in the folder the page cache's
 * processes share, which RecordPolicyReports adds to its table a minute
 * later. A sink (docs/architecture/operations.md, Telemetry sinks): nothing
 * decides anything from it, and a line that can't be written is dropped.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Policy\Reports;

final readonly class PolicyReportSpool
{
    /** The spool's folder, inside the page cache's, beside the 404 log's. */
    public const string FOLDER = 'csp';

    /** The file reports are appended to. */
    public const string OPEN = 'csp.open';

    /** The file the last run closed, read by the next, when no request can still be writing to it. */
    public const string CLOSED = 'csp.closed';

    /** The file a run is reading; one left behind is read by the next run first. */
    public const string READING = 'csp.reading';

    /** A second file a run may be reading, when the first is one an earlier run left behind. */
    public const string READING_NEXT = 'csp.reading2';

    /** The most files set aside that a run could not store; the oldest go first. */
    public const int MOST_BAD = 3;

    /** Past this size the open file takes no more lines, so a stopped scheduler can't fill the disk. */
    public const int MOST_BYTES = 67_108_864;

    /**
     * The spool's folder, from the page cache's file store's.
     */
    public function folder(): string
    {
        return rtrim((string) config('page_cache.stores.file.path'), '/').'/'.self::FOLDER;
    }

    /**
     * The files set aside because their lines could not be stored, oldest
     * first, as full paths.
     *
     * @return list<string>
     */
    public function badFiles(): array
    {
        $files = glob($this->folder().'/csp.*.bad') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Renames a file a run could not store to a name of its own beside it,
     * and deletes the oldest set-aside files past MOST_BAD. Never throws.
     */
    public function setAside(string $file): void
    {
        $name = sprintf('%s/csp.%010d-%s.bad', $this->folder(), time(), bin2hex(random_bytes(4)));

        if (! @rename($file, $name)) {
            @unlink($file);

            return;
        }

        foreach (array_slice($this->badFiles(), 0, -self::MOST_BAD) as $oldest) {
            @unlink($oldest);
        }
    }

    /**
     * Notes one report: directive, blocked, page, disposition, sample and
     * time, tab-separated; the three that may hold a tab or a line break
     * are percent-encoded, so none can split a line.
     */
    public function append(PolicyReport $report): void
    {
        $sample = $report->sample === null ? '' : rawurlencode((string) json_encode($report->sample));

        $this->write(implode("\t", [
            $report->directive,
            rawurlencode($report->blocked),
            rawurlencode($report->path),
            $report->disposition,
            $sample,
            $report->at,
        ]));
    }

    /**
     * One line at the open file's end, whole, under an exclusive lock, so
     * two requests never interleave theirs. Never reported when it fails:
     * it would repeat on every request of a flood.
     */
    private function write(string $line): void
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
