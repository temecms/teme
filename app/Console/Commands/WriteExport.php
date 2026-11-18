<?php

/**
 * teme:export — writes the site's content, people and media as NDJSON files, the media's originals and a manifest into a new folder at the server, as the operator (I-365, I-2407).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Export\ExportFailed;
use App\Domain\Export\Operations\ExportSite;
use App\Domain\Export\Operations\Input\ExportSiteInput;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WriteExport extends Command
{
    /** @var string */
    protected $signature = 'teme:export {folder? : A folder that doesn’t exist yet; storage/app/exports/<date and time> when none is given}';

    /** @var string */
    protected $description = 'Export the site’s content, people and media as NDJSON files, with the media’s originals and a manifest';

    public function handle(ExportSite $export): int
    {
        // The operator, as teme:secrets:reencrypt is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        // SIGINT and SIGTERM, where PHP has pcntl (never on Windows, and untested): exit() runs the export's own cleanup.
        $this->trap([2, 15], function (): void {
            exit(130);
        });

        $folder = $this->folder();
        $this->line("Exporting this site to {$folder} …");

        try {
            $result = $export->execute(new ExportSiteInput($folder));
        } catch (ValidationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        } catch (ExportFailed $failed) {
            $this->error($failed->leftBehind === null
                ? "The export stopped: {$failed->getMessage()} Nothing was left behind."
                : "The export stopped: {$failed->getMessage()} Teme couldn’t remove {$failed->leftBehind}: delete it by hand.");

            return self::FAILURE;
        } catch (Throwable $failed) {
            // Before the writer began (its staging folder couldn't be made): nothing was written, and the transaction rolled back.
            $this->error("The export stopped: {$failed->getMessage()} Nothing was left behind.");

            return self::FAILURE;
        }

        foreach ($result->files as $file) {
            $this->line('  '.number_format($file->lines).' '.trans_choice($file->noun, $file->lines));
        }

        $this->line('  '.trans_choice(':count original copied, :size|:count originals copied, :size', $result->media->files, [
            'count' => number_format($result->media->files),
            'size' => Number::fileSize($result->media->bytes, precision: 1),
        ]));

        $this->info("Exported to {$result->folder}. Its manifest.json lists every file with its SHA-256, and what the export leaves out.");
        $this->line('It holds people’s email addresses and every draft: keep it as safely as the site’s database, and delete it from this server once it’s copied.');

        if ($result->media->missing !== []) {
            $this->warn(trans_choice(
                ':count original couldn’t be read and isn’t in the export: its record says so, and its ID is under media.missing in manifest.json.|:count originals couldn’t be read and aren’t in the export: their records say so, and their IDs are under media.missing in manifest.json.',
                count($result->media->missing),
                ['count' => number_format(count($result->media->missing))],
            ));
        }

        if ($result->media->differs !== []) {
            $this->warn(trans_choice(
                ':count original has changed since it was uploaded; it was exported as it is now, and its ID is under media.differs in manifest.json.|:count originals have changed since they were uploaded; they were exported as they are now, and their IDs are under media.differs in manifest.json.',
                count($result->media->differs),
                ['count' => number_format(count($result->media->differs))],
            ));
        }

        $this->line('Peak memory: '.Number::fileSize(memory_get_peak_usage(true), precision: 1).'.');

        return self::SUCCESS;
    }

    /**
     * The folder named, or a new one under storage/app/exports named for
     * now. That parent is made private if missing; Teme's own disks never
     * serve it.
     */
    private function folder(): string
    {
        $given = $this->argument('folder');

        if (is_string($given) && trim($given) !== '') {
            return $given;
        }

        $parent = storage_path('app/exports');

        if (! is_dir($parent)) {
            $mask = umask(0077);

            try {
                // A failure here is the Operation's to word: the parent doesn't exist.
                @mkdir($parent, 0700, true);
            } finally {
                umask($mask);
            }
        }

        return str_replace('\\', '/', $parent).'/'.Date::now()->format('Y-m-d-His');
    }
}
