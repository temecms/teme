<?php

declare(strict_types=1);

namespace Tests\Fixtures\Export;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportedSite;
use App\Domain\Export\ExportFolder;
use App\Domain\Export\ExportKind;
use App\Domain\Export\MediaTally;
use App\Domain\Export\Operations\ExportSite;
use App\Domain\Export\Operations\Input\ExportSiteInput;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Runs the export as the operator into a fresh temporary folder, reads
 * back what it wrote, and removes it afterwards.
 */
final class ExportAs
{
    /** A path under the system's temporary folder that doesn't exist yet, with forward slashes. */
    public static function freshFolder(): string
    {
        return str_replace('\\', '/', rtrim(sys_get_temp_dir(), '\\/')).'/teme-export-test-'.Str::lower((string) Str::ulid());
    }

    public static function operator(string $folder, ?int $pageSize = null): ExportedSite
    {
        app()->instance(Elevation::class, new OperatorElevation);

        return app(ExportSite::class)->execute(new ExportSiteInput($folder, $pageSize));
    }

    /**
     * Each line of one kind's file, decoded as arrays.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(string $folder, string $kind): array
    {
        $lines = [];

        foreach (file("{$folder}/{$kind}.ndjson", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $lines[] = json_decode($line, true, 1024, JSON_THROW_ON_ERROR);
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(string $folder): array
    {
        return json_decode((string) file_get_contents("{$folder}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The staging folders an export into $folder left beside it.
     *
     * @return list<string>
     */
    public static function leftovers(string $folder): array
    {
        return glob(dirname($folder).'/.'.basename($folder).'.partial-*') ?: [];
    }

    /**
     * One kind's lines, read straight from the kind into a throwaway
     * folder (for tests only: it holds them all).
     *
     * @return list<array<string, mixed>>
     */
    public static function kindLines(ExportKind $kind, ?int $pageSize = null): array
    {
        $folder = ExportFolder::open(self::freshFolder());

        try {
            return iterator_to_array($kind->lines(new ExportContext($folder, new MediaTally, $pageSize)), false);
        } finally {
            $folder->discard();
        }
    }

    public static function remove(string $folder): void
    {
        (new Filesystem)->deleteDirectory($folder);

        foreach (self::leftovers($folder) as $leftover) {
            (new Filesystem)->deleteDirectory($leftover);
        }
    }

    /**
     * A line's keys, a nested record's as parent.child; a list, an object
     * from JSONB and a null are leaves.
     *
     * @param  array<string, mixed>  $line
     *
     * @return list<string>
     */
    public static function flatKeys(array $line, string $prefix = ''): array
    {
        $keys = [];

        foreach ($line as $key => $value) {
            $keys[] = $prefix.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                array_push($keys, ...self::flatKeys($value, $prefix.$key.'.'));
            }
        }

        return $keys;
    }
}
