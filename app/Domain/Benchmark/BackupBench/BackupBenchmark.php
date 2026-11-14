<?php

declare(strict_types=1);

namespace App\Domain\Benchmark\BackupBench;

use App\Domain\Backups\BackupResult;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\MediaInventory;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\ConfirmRecoveryKey;
use App\Domain\Backups\Operations\Input\ConfirmRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\RequestBackupInput;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\RequestBackup;
use App\Domain\Backups\Operations\SetUpBackups;
use App\Domain\Backups\PruneBackups;
use App\Domain\Backups\Queries\BackupJobs;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Queries\PlaceStorage;
use App\Domain\Backups\Tracking\TrackedBackup;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Backups\Verify\VerifyBackups;
use App\Domain\Benchmark\BenchmarkSeeder;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Spatie\Permission\PermissionRegistrar;

/**
 * The backup benchmark (I-367): the synthetic site, then each backup, check
 * and restore a site has, in order, as the operator, each timed whole and
 * step by step; and its report.
 */
final class BackupBenchmark
{
    public function __construct(
        private readonly BenchmarkSeeder $seeder,
        private readonly BackupBenchSite $site,
        private readonly SetUpBackups $setUp,
        private readonly ConfirmRecoveryKey $confirm,
        private readonly BackupSite $backups,
        private readonly RequestBackup $request,
        private readonly TrackedBackup $tracked,
        private readonly BackupJobs $jobs,
        private readonly VerifyBackups $verify,
        private readonly PruneBackups $prune,
        private readonly PlaceStorage $storage,
        private readonly BackupSetup $setup,
    ) {}

    /**
     * Builds the site and takes every measure.
     *
     * @return array{
     *     items: int, protected: int, images: int, files: int, mediaBytes: int, originalBytes: int, databaseBytes: int,
     *     scale: float, seedSeconds: float, filesSeconds: float,
     *     night: array{images: int, items: int, files: int, bytes: int},
     *     runs: list<array{name: string, succeeded: bool, sentence: string|null, problems: list<string>, seconds: float, peakMemory: int, firstAt: float|null, steps: list<array{step: string, seconds: float}>, figures: array<string, mixed>}>,
     *     backUpNow: float|null,
     *     storage: array{bytes: int|null, sentence: string|null, seconds: float},
     *     keyFile: string, place: string, failed: list<string>
     * }
     */
    public function run(BackupBenchOptions $options): array
    {
        $began = microtime(true);
        $this->seeder->seed($options->items, $options->images, $options->comments, redirects: $options->redirects, notFound: $options->notFound, aiActivity: $options->aiActivity);
        $seedSeconds = microtime(true) - $began;

        $roots = MediaInventory::roots();
        $began = microtime(true);
        $this->site->shape(1, $options->scale);
        $written = $this->site->write(1, $roots);
        $protected = $this->site->secrets($options->protectEvery);
        $filesSeconds = microtime(true) - $began;
        DB::statement('ANALYZE');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $databaseBytes = (int) DB::scalar('SELECT pg_database_size(current_database())');

        // As an operator sets backups up (I-2401); the key is kept for the restore onto an empty server.
        if (! is_dir($options->folder)) {
            mkdir($options->folder, 0700, true);
        }

        $place = $options->folder.DIRECTORY_SEPARATOR.'place';
        $shown = $this->setUp->execute(new SetUpBackupsInput(NewPlace::folder('Benchmark place', $place)));
        [$first, $second] = $shown->challenge->positions;
        $this->confirm->execute(new ConfirmRecoveryKeyInput($shown->challenge, [$first => $shown->key->group($first), $second => $shown->key->group($second)]));
        $keyFile = $options->folder.DIRECTORY_SEPARATOR.'recovery-key.txt';
        // Private before the key is in it, never after.
        touch($keyFile);
        chmod($keyFile, 0600);
        file_put_contents($keyFile, $shown->key->value()."\n");

        $runs = [$this->timed('first', fn (Closure $say): BackupResult => $this->backups->run(BackupTrigger::Command, null, $say))];

        $night = $this->site->night($options->images, $options->night, $options->scale, $roots);
        $adminId = (string) DB::table('users')->where('email', 'admin@bench.test')->value('id');
        $requested = microtime(true);
        $jobId = (string) $this->request->execute(new RequestBackupInput($adminId));
        $runs[] = $this->timed('night', fn (Closure $say): ?BackupResult => $this->tracked->run($jobId, $say), $jobId);
        $backUpNow = $runs[1]['firstAt'] === null ? null : round($runs[1]['firstAt'] - $requested, 1);

        $runs[] = $this->timed('read-back', fn (Closure $say): BackupResult => $this->verify->readBack(BackupTrigger::Command, null, $say));
        $runs[] = $this->timed('prune', fn (Closure $say): BackupResult => $this->prune->run(BackupTrigger::Command, null, $say));

        $began = microtime(true);
        $measured = $this->storage->measure((string) $this->setup->placesInUse()->first()?->id);
        $storage = [...$measured, 'seconds' => round(microtime(true) - $began, 1)];

        $runs[] = SpareDatabase::isNamed()
            ? $this->timed('test-restore', fn (Closure $say): BackupResult => $this->verify->testRestore(BackupTrigger::Command, null, $say))
            : $this->skipped('test-restore', 'Skipped: no spare database is named.');

        return [
            'items' => $options->items,
            'protected' => $protected,
            'images' => $options->images,
            'files' => $written['files'],
            'mediaBytes' => $written['bytes'],
            'originalBytes' => $written['originalBytes'],
            'databaseBytes' => $databaseBytes,
            'scale' => $options->scale,
            'seedSeconds' => round($seedSeconds, 1),
            'filesSeconds' => round($filesSeconds, 1),
            'night' => ['images' => $options->night, 'items' => $options->night * 10, 'files' => $night['files'], 'bytes' => $night['bytes']],
            'runs' => $runs,
            'backUpNow' => $backUpNow,
            'storage' => $storage,
            'keyFile' => $keyFile,
            'place' => $place,
            'failed' => array_values(array_map(fn (array $run): string => $run['name'], array_filter($runs, fn (array $run): bool => ! $run['succeeded']))),
        ];
    }

    /**
     * The report, in the words of docs/benchmarks/{label}-backup.md.
     *
     * @param  array<string, mixed>  $result  run()'s result
     * @param  array{label: string, date: string, postgres: string, restic: string, machine: string, load: string}  $about
     */
    public function report(array $result, array $about): string
    {
        $size = fn (int|float $bytes): string => Number::fileSize((int) round($bytes), precision: 1);
        $secs = fn (float $seconds): string => number_format($seconds, 1);
        $count = fn (int $n): string => number_format($n);
        $scale = (float) $result['scale'];
        $percent = rtrim(rtrim(number_format($scale * 100, 4, '.', ''), '0'), '.');
        $fullMedia = $result['mediaBytes'] / $scale;
        $fullOriginal = $result['originalBytes'] / $scale;
        $runs = [];

        foreach ($result['runs'] as $run) {
            $runs[$run['name']] = $run;
        }

        $first = $runs['first'];
        $night = $result['night'];
        $nightImages = $night['images'] === 1 ? '1 new image' : $count($night['images']).' new images';
        $nightItems = $night['items'] === 1 ? '1 item' : $count($night['items']).' items';
        $label = [
            'first' => 'The first backup',
            'night' => "The nightly backup, from Back up now, after {$nightImages} and {$nightItems} saved again",
            'read-back' => 'The nightly read-back of a seventh',
            'prune' => 'The weekly prune and check',
            'test-restore' => 'The weekly test restore',
        ];
        $heading = [
            'first' => 'The first backup',
            'night' => 'The nightly backup',
            'read-back' => 'The nightly read-back',
            'prune' => 'The weekly prune and check',
            'test-restore' => 'The weekly test restore',
        ];

        $out = "# Backup benchmark — {$about['label']}\n\n";
        $out .= "- **Date:** {$about['date']} · **PostgreSQL:** {$about['postgres']} · **restic:** {$about['restic']} · **Machine:** {$about['machine']} · **Host load:** {$about['load']}\n";
        $out .= "- **The site:** {$count($result['items'])} items, {$count($result['protected'])} of them password-protected; {$count($result['images'])} images in {$count($result['files'])} files, {$size($result['mediaBytes'])}; the database {$size($result['databaseBytes'])}. Seeded in {$secs($result['seedSeconds'])} s; the images’ files written in {$secs($result['filesSeconds'])} s.\n";
        $out .= "- **The size:** every file is {$percent}% of its full size, so a site of these images with every file at full size would hold about {$size($fullMedia)}, its originals about {$size($fullOriginal)} (I-367). Counts of images, files and rows are full.\n\n";
        $out .= "| Run | Seconds | Peak memory | Added to the place | Result |\n|---|---|---|---|---|\n";

        foreach ($label as $name => $words) {
            $run = $runs[$name] ?? null;

            if ($run === null) {
                continue;
            }

            $added = in_array($name, ['first', 'night'], true) && isset($run['figures']['bytes_added']) ? $size((int) $run['figures']['bytes_added']) : '—';
            $result_ = $run['succeeded'] ? 'Done' : 'Failed: '.($run['sentence'] ?? '');

            foreach ($run['problems'] as $problem) {
                $result_ .= '; '.$problem;
            }

            $out .= "| {$words} | {$secs($run['seconds'])} | {$size($run['peakMemory'])} | {$added} | {$result_} |\n";
        }

        $out .= "\nPeak memory is Teme’s own PHP process; restic and pg_dump run beside it and aren’t counted.\n\n## Each step\n";

        foreach ($heading as $name => $title) {
            $out .= "\n### {$title}\n\n| Step | Seconds |\n|---|---|\n";

            foreach (($runs[$name]['steps'] ?? []) as $step) {
                $out .= "| {$step['step']} | {$secs($step['seconds'])} |\n";
            }
        }

        $out .= "\n## What the runs say\n\n";
        $out .= $result['backUpNow'] === null
            ? '- **Back up now** never reached its first step: '.($runs['night']['sentence'] ?? '')."\n"
            : "- **Back up now** took {$secs($result['backUpNow'])} s from the request to its first step, without the scheduler’s minute: on a server the scheduler takes a waiting backup within a minute, once no backup, prune or check holds the backup lock (I-3172).\n";
        $out .= $result['storage']['bytes'] === null
            ? "- **What the place holds:** restic didn’t say in {$secs($result['storage']['seconds'])} s: {$result['storage']['sentence']} (I-2986).\n"
            : "- **What the place holds:** {$size($result['storage']['bytes'])}, counted by `restic stats --mode raw-data` in {$secs($result['storage']['seconds'])} s; Tools › Backups waits up to 60 s for it (I-2986).\n";
        $out .= "- **The manifest in memory:** the first backup’s peak memory, {$size($first['peakMemory'])}, holds every original’s SHA-256 (I-2481).\n";
        $dump = (int) ($first['figures']['database_bytes'] ?? 0);
        $out .= "- **Spare disk:** a backup streams the database’s dump into restic and needs no room for it. The weekly test restore needs room for the dump, {$size($dump)}, and a spare database the size of the site’s, about {$size($result['databaseBytes'])}. A restore needs room for the dump and the media, {$size($result['mediaBytes'])}.\n";

        $mediaSeconds = 0.0;

        foreach ($first['steps'] as $step) {
            if (str_starts_with($step['step'], 'Backing up ')) {
                $mediaSeconds = $step['seconds'];
                break;
            }
        }

        $out .= "- **At full size:** the first backup’s media step took {$secs($mediaSeconds)} s for {$size($result['mediaBytes'])}; at full size, about {$size($fullMedia)}, it would take about {$secs($mediaSeconds / $scale)} s at this machine’s rate. A place off the server adds the time its link takes to carry that much.\n";

        return $out;
    }

    /**
     * One run, timed whole and by its steps, with its PHP peak memory and its
     * recorded figures. A run that didn't start (null) has its job's words.
     *
     * @param  Closure(Closure(string): void): (BackupResult|null)  $work
     *
     * @return array{name: string, succeeded: bool, sentence: string|null, problems: list<string>, seconds: float, peakMemory: int, firstAt: float|null, steps: list<array{step: string, seconds: float}>, figures: array<string, mixed>}
     */
    private function timed(string $name, Closure $work, ?string $jobId = null): array
    {
        $clock = new StepClock;
        memory_reset_peak_usage();
        $began = microtime(true);
        $result = $work($clock->say());
        $ended = microtime(true);
        $record = $result === null ? null : BackupRun::query()->find($result->runId);

        return [
            'name' => $name,
            'succeeded' => $result !== null && $result->succeeded,
            'sentence' => $result === null ? 'It didn’t start: '.($jobId === null ? '' : (string) $this->jobs->message($jobId)) : $result->sentence,
            'problems' => $result === null ? [] : $result->problems,
            'seconds' => round($ended - $began, 1),
            'peakMemory' => memory_get_peak_usage(true),
            'firstAt' => $clock->firstAt(),
            'steps' => $clock->steps($ended),
            'figures' => $record === null ? [] : $record->figures,
        ];
    }

    /**
     * A run that was not made: failed, with the words given.
     *
     * @return array{name: string, succeeded: bool, sentence: string|null, problems: list<string>, seconds: float, peakMemory: int, firstAt: float|null, steps: list<array{step: string, seconds: float}>, figures: array<string, mixed>}
     */
    private function skipped(string $name, string $sentence): array
    {
        return ['name' => $name, 'succeeded' => false, 'sentence' => $sentence, 'problems' => [], 'seconds' => 0.0, 'peakMemory' => 0, 'firstAt' => null, 'steps' => [], 'figures' => []];
    }
}
