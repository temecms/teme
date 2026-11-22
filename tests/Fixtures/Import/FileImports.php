<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Export files uploaded and read for the wiring's tests: an uploaded file
 * read to its end through the reading job, and an import run of it.
 */
final class FileImports
{
    /**
     * An uploaded file holding one of the reader's fixtures, or the bytes given, about to be read.
     */
    public static function uploaded(string $fixture, User $by, ?string $bytes = null): ImportFile
    {
        $bytes ??= (string) file_get_contents(ExportFiles::path($fixture));
        $file = ImportFile::query()->create([
            'uploaded_by' => $by->id, 'name' => $fixture, 'size_bytes' => strlen($bytes), 'received_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'state' => 'reading',
        ]);
        Storage::disk('local')->put($file->path(), $bytes);

        return $file;
    }

    /**
     * A fixture uploaded and read to its end by $by: the file, ready, and its site.
     */
    public static function ready(string $fixture, User $by, ?string $bytes = null): ImportFile
    {
        $file = self::uploaded($fixture, $by, $bytes);
        $job = new ReadImportFile($file->id, JobActor::person($by->id));
        $fake = Queue::getFacadeRoot();

        while ($job !== null) {
            Queue::fake();
            $job->handle();
            $queued = Queue::pushedJobs()[ReadImportFile::class] ?? [];
            $job = $queued === [] ? null : $queued[0]['job'];
        }

        Queue::swap($fake);

        return $file->refresh();
    }

    /**
     * Starts an import of the file's site from the file as the operator, and
     * lets the synchronous queue carry it to its end. The run, fresh.
     *
     * @param  array<string, mixed>  $choices
     */
    public static function run(ImportFile $file, array $choices = []): ImportRun
    {
        /** @var array{runId: string} $result */
        $result = scElevated(fn (): array => app(StartImportRun::class)->execute(
            new StartImportRunInput((string) $file->source_id, ImportRunKind::Full, ['pause_ms' => 0], $choices, null, (string) $file->id),
        ));

        return ImportRun::query()->findOrFail($result['runId']);
    }

    /**
     * The file's site.
     */
    public static function site(ImportFile $file): ImportSource
    {
        return ImportSource::query()->findOrFail($file->source_id);
    }
}
