<?php

declare(strict_types=1);

namespace App\Domain\Media\Storage;

use App\Domain\Media\MediaFiles;
use App\Domain\Media\MediaLocations;
use App\Domain\Media\Models\MediaMove;
use App\Domain\Media\Operations\EndMediaMove;
use App\Domain\Media\Operations\Input\EndMediaMoveInput;
use App\Domain\Media\Operations\Input\RecordMediaMoveProgressInput;
use App\Domain\Media\Operations\Input\StartMediaMoveInput;
use App\Domain\Media\Operations\RecordMediaMoveProgress;
use App\Domain\Media\Operations\StartMediaMove;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies the files the media rows name from one location to another
 * (I-3456), outside any transaction, as a backup runs: in chunks of rows by
 * ID, resumable from the run's cursor, each file checked by size and every
 * original read back against its recorded SHA-256 (every file with deep).
 * It never lists a folder, and records each chunk through the operator's
 * move Operations. deleteSource() removes the source's copies after a
 * verified move, through MoveGate.
 */
final class MediaMover
{
    /** Exit codes the command answers with. */
    public const int VERIFIED = 0;

    public const int FAILURES = 1;

    public const int REFUSED = 2;

    public const int STOPPED = 3;

    public function __construct(
        private readonly MediaMoveLock $lock,
        private readonly MoveGate $gate,
        private readonly StartMediaMove $start,
        private readonly RecordMediaMoveProgress $record,
        private readonly EndMediaMove $end,
    ) {}

    /**
     * Refuses a move that can't be made, before anything is written: the
     * same location twice, an unknown one, a target public disk without an
     * address, or both target disks on one bucket.
     *
     * @param  list<'public'|'originals'>  $roles
     *
     * @throws MediaMoveRefused
     */
    public static function refuse(string $from, string $to, array $roles): void
    {
        foreach ([$from, $to] as $name) {
            if (! MediaLocations::exists($name)) {
                throw new MediaMoveRefused(__('There is no media location “:name”. The locations are :names.', ['name' => $name, 'names' => implode(', ', MediaLocations::names())]));
            }
        }

        if ($from === $to) {
            throw new MediaMoveRefused(__('The media are already in “:name”: name another location to move them to.', ['name' => $to]));
        }

        $target = MediaLocations::disks($to);

        if (in_array('public', $roles, true) && (string) (self::disk($target['public'])->getConfig()['url'] ?? '') === '') {
            throw new MediaMoveRefused(__('The disk “:disk” has no public address, so the site couldn’t show what moved there. For the bucket, set MEDIA_BUCKET_URL.', ['disk' => $target['public']]));
        }

        $public = self::disk($target['public']);
        $originals = self::disk($target['originals']);

        if (count($roles) === 2 && $public instanceof AwsS3V3Adapter && $originals instanceof AwsS3V3Adapter
            && ($public->getConfig()['bucket'] ?? null) === ($originals->getConfig()['bucket'] ?? null)
            && rtrim(strtolower((string) ($public->getConfig()['endpoint'] ?? '')), '/') === rtrim(strtolower((string) ($originals->getConfig()['endpoint'] ?? '')), '/')) {
            throw new MediaMoveRefused(__('The public bucket and the originals bucket are the same bucket. Use two: a public bucket would serve the originals too.'));
        }
    }

    /**
     * What a move would cover, from the rows alone: items, files and bytes.
     * Writes nothing.
     *
     * @param  list<'public'|'originals'>  $roles
     *
     * @return array{items: int, files: int, bytes: int}
     */
    public function count(array $roles, ?CarbonImmutable $since): array
    {
        $counted = ['items' => 0, 'files' => 0, 'bytes' => 0];
        $cursor = null;

        while (($rows = self::chunk($cursor, $since, 500)) !== []) {
            foreach ($rows as $row) {
                $cursor = (string) $row->id;
                $counted['items']++;

                foreach (self::filesOf($row, $roles) as $file) {
                    $counted['files']++;
                    $counted['bytes'] += $file['bytes'];
                }
            }
        }

        return $counted;
    }

    /**
     * When a catch-up starts from: the start of the latest finished copy in
     * this direction for these roles; null when there is none.
     *
     * @param  list<'public'|'originals'>  $roles
     */
    public static function catchUpSince(string $from, string $to, array $roles): ?CarbonImmutable
    {
        $sorted = $roles;
        sort($sorted);

        $at = DB::table('media_moves')
            ->where(['kind' => 'copy', 'from_location' => $from, 'to_location' => $to, 'state' => 'finished'])
            ->whereRaw('roles = ?::jsonb', [json_encode($sorted, JSON_THROW_ON_ERROR)])
            ->orderByDesc('started_at')
            ->value('started_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    /**
     * Copies every file in scope from $from to $to under the move lock,
     * resuming a stopped or killed run of the same scope. $say hears one
     * line a chunk; $advance hears each item handled; $stopping is asked
     * after each item, and a true answer ends the run stopped.
     *
     * @param  list<'public'|'originals'>  $roles
     * @param  Closure(string): void  $say
     * @param  Closure(int): void  $advance
     * @param  Closure(): bool  $stopping
     *
     * @return array{code: int, runId: string|null, sentence: string}
     */
    public function copy(string $from, string $to, array $roles, bool $catchUp, bool $deep, int $chunk, Closure $say, Closure $advance, Closure $stopping): array
    {
        try {
            self::refuse($from, $to, $roles);

            return $this->lock->during(function () use ($from, $to, $roles, $catchUp, $deep, $chunk, $say, $advance, $stopping): array {
                $since = null;

                if ($catchUp) {
                    $since = self::catchUpSince($from, $to, $roles)
                        ?? throw new MediaMoveRefused(__('There is no finished copy from “:from” to “:to” to catch up after. Run a full copy first, without --catch-up.', ['from' => $from, 'to' => $to]));
                }

                $run = $this->begin('copy', $from, $to, $roles, $since, $deep, fn (): int => $this->count($roles, $since)['items']);
                $say(__('Copying :items media items from “:from” to “:to”.', ['items' => number_format($run->items_total - $run->items_done), 'from' => $from, 'to' => $to]));

                return $this->walk($run, $chunk, $say, $advance, $stopping, fn (array $file, object $row): array => $this->copyFile($file, $from, $to, $deep));
            });
        } catch (MediaMoveRefused $refused) {
            return ['code' => self::REFUSED, 'runId' => null, 'sentence' => $refused->getMessage()];
        }
    }

    /**
     * Deletes the source's copy of every file in scope, under the move lock,
     * only when MoveGate allows it, each one only after its target copy is
     * checked again. Never a folder.
     *
     * @param  list<'public'|'originals'>  $roles
     * @param  Closure(string): void  $say
     * @param  Closure(int): void  $advance
     * @param  Closure(): bool  $stopping
     *
     * @return array{code: int, runId: string|null, sentence: string}
     */
    public function deleteSource(string $from, string $to, array $roles, int $chunk, Closure $say, Closure $advance, Closure $stopping): array
    {
        try {
            self::refuse($from, $to, $roles);

            return $this->lock->during(function () use ($from, $to, $roles, $chunk, $say, $advance, $stopping): array {
                $refusal = $this->gate->refusal($from, $to, $roles);

                if ($refusal !== null) {
                    throw new MediaMoveRefused($refusal);
                }

                $run = $this->begin('delete_source', $from, $to, $roles, null, false, fn (): int => $this->count($roles, null)['items']);
                $say(__('Deleting the copies in “:from” of :items media items, each after checking its copy in “:to”.', ['items' => number_format($run->items_total - $run->items_done), 'from' => $from, 'to' => $to]));

                return $this->walk($run, $chunk, $say, $advance, $stopping, function (array $file) use ($from, $to): array {
                    // A backup may have started since the gate was asked: wait for nothing, stop instead.
                    if ($this->gate->backupRunning()) {
                        throw new MediaMoveRefused(__('A backup started, so the deletion stopped. Run it again when the backup has finished.'));
                    }

                    return $this->deleteFile($file, $from, $to);
                });
            });
        } catch (MediaMoveRefused $refused) {
            return ['code' => self::REFUSED, 'runId' => null, 'sentence' => $refused->getMessage()];
        }
    }

    /**
     * The run to work on: a stopped or killed one of the same kind,
     * direction, roles and scope, resumed; else a new one.
     *
     * @param  list<'public'|'originals'>  $roles
     * @param  Closure(): int  $total
     */
    private function begin(string $kind, string $from, string $to, array $roles, ?CarbonImmutable $since, bool $deep, Closure $total): MediaMove
    {
        $sorted = $roles;
        sort($sorted);

        // Under the lock, a run still marked running was killed.
        $left = MediaMove::query()
            ->where(['kind' => $kind, 'from_location' => $from, 'to_location' => $to, 'scope' => $since === null ? 'all' : 'changed', 'deep' => $deep])
            ->whereIn('state', ['running', 'stopped'])
            ->whereRaw('roles = ?::jsonb', [json_encode($sorted, JSON_THROW_ON_ERROR)])
            ->when($since !== null, fn ($query) => $query->where('changed_since', $since))
            ->orderByDesc('started_at')
            ->first();

        // Any other run of this kind and direction still marked running was killed too, with other
        // options: it ends stopped, so it never blocks this one (resuming it is running its own options again).
        MediaMove::query()
            ->where(['kind' => $kind, 'from_location' => $from, 'to_location' => $to, 'state' => 'running'])
            ->when($left !== null, fn ($query) => $query->whereKeyNot($left->id))
            ->pluck('id')
            ->each(fn (string $killed) => $this->end->execute(new EndMediaMoveInput($killed, 'stopped', __('This stopped before it finished: the process running it ended.'))));

        $id = $this->start->execute(new StartMediaMoveInput(
            kind: $kind,
            from: $from,
            to: $to,
            roles: $roles,
            scope: $since === null ? 'all' : 'changed',
            changedSince: $since,
            deep: $deep,
            siteDisks: ['public' => (string) config('media.disk'), 'originals' => (string) config('media.originals_disk')],
            itemsTotal: $left === null ? $total() : 0,
            resumeId: $left?->id,
        ));

        return MediaMove::query()->findOrFail($id);
    }

    /**
     * Walks the run's rows from its cursor, a chunk at a time, handling
     * each file with $handle and recording each chunk.
     *
     * @param  Closure(string): void  $say
     * @param  Closure(int): void  $advance
     * @param  Closure(): bool  $stopping
     * @param  Closure(array{role: 'public'|'originals', path: string, bytes: int, sha256: string|null}, object): array{outcome: string, bytes?: int, reason?: string}  $handle
     *
     * @return array{code: int, runId: string, sentence: string}
     */
    private function walk(MediaMove $run, int $chunk, Closure $say, Closure $advance, Closure $stopping, Closure $handle): array
    {
        /** @var list<'public'|'originals'> $roles */
        $roles = $run->roles;
        $cursor = $run->cursor;
        $began = microtime(true);
        $totals = ['items' => $run->items_done, 'copied' => $run->files_copied, 'present' => $run->files_present, 'deleted' => $run->files_deleted, 'holes' => $run->holes, 'failures' => $run->failures, 'bytes' => $run->bytes_copied];
        $stopped = false;

        try {
            while (! $stopped && ($rows = self::chunk($cursor, $run->changed_since, $chunk)) !== []) {
                $counts = ['items' => 0, 'copied' => 0, 'present' => 0, 'deleted' => 0, 'holes' => 0, 'failures' => 0, 'bytes' => 0];
                $problems = [];
                $last = null;

                foreach ($rows as $row) {
                    foreach (self::filesOf($row, $roles) as $file) {
                        $done = $handle($file, $row);
                        $counts[$done['outcome']]++;
                        $counts['bytes'] += $done['bytes'] ?? 0;

                        if (isset($done['reason'])) {
                            $problems[] = ['id' => (string) $row->id, 'role' => $file['role'], 'path' => $file['path'], 'reason' => $done['reason']];
                        }
                    }

                    $last = (string) $row->id;
                    $counts['items']++;
                    $advance(1);

                    if ($stopping()) {
                        $stopped = true;

                        break;
                    }
                }

                $this->record->execute(new RecordMediaMoveProgressInput(
                    runId: $run->id,
                    cursor: $last,
                    itemsDone: $counts['items'],
                    filesCopied: $counts['copied'],
                    filesPresent: $counts['present'],
                    filesDeleted: $counts['deleted'],
                    bytesCopied: $counts['bytes'],
                    holes: $counts['holes'],
                    failures: $counts['failures'],
                    problems: $problems,
                ));
                $cursor = $last;

                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }

                $say($this->progress($run, $totals, $began));
            }
        } catch (MediaMoveRefused $refused) {
            $this->end->execute(new EndMediaMoveInput($run->id, 'stopped', $refused->getMessage()));

            return ['code' => self::STOPPED, 'runId' => $run->id, 'sentence' => $refused->getMessage()];
        } catch (Throwable $failure) {
            $this->end->execute(new EndMediaMoveInput($run->id, 'stopped', __('The move stopped on an error; run it again to resume.')));

            throw $failure;
        }

        if ($stopped) {
            $sentence = __('Stopped after :items items. Run the same command again to resume where it stopped.', ['items' => number_format($totals['items'])]);
            $this->end->execute(new EndMediaMoveInput($run->id, 'stopped', $sentence));

            return ['code' => self::STOPPED, 'runId' => $run->id, 'sentence' => $sentence];
        }

        $sentence = $this->ending($run, $totals);
        $this->end->execute(new EndMediaMoveInput($run->id, 'finished', $sentence));

        return ['code' => $totals['failures'] === 0 ? self::VERIFIED : self::FAILURES, 'runId' => $run->id, 'sentence' => $sentence];
    }

    /**
     * Copies one file unless the target already has it, then checks the
     * target's copy: its size always, and for an original, or any file with
     * deep, its SHA-256. An ETag is never trusted.
     *
     * @param  array{role: 'public'|'originals', path: string, bytes: int, sha256: string|null}  $file
     *
     * @return array{outcome: string, bytes?: int, reason?: string}
     */
    private function copyFile(array $file, string $from, string $to, bool $deep): array
    {
        $source = self::disk(MediaLocations::disks($from)[$file['role']]);
        $target = self::disk(MediaLocations::disks($to)[$file['role']]);
        $path = $file['path'];

        try {
            $size = $target->fileExists($path) ? $target->fileSize($path) : null;

            if (! $source->fileExists($path)) {
                // Never there, or already moved and deleted: right on the target counts; else a hole.
                if ($size === null) {
                    return ['outcome' => 'holes', 'reason' => __('missing in both locations')];
                }

                return $this->checked($file, $target, null, $deep, 'present');
            }

            $sourceSize = $source->fileSize($path);
            $sourceSha = $file['sha256'] !== null || $deep ? self::sha256($source, $path) : null;

            if ($file['sha256'] !== null && $sourceSha !== $file['sha256']) {
                return ['outcome' => 'failures', 'reason' => __('the copy in “:from” differs from its recorded checksum', ['from' => $from])];
            }

            if ($size === $sourceSize) {
                return $this->checked($file, $target, $sourceSha, $deep, 'present');
            }

            $stream = $source->readStream($path);

            try {
                $target->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($target->fileSize($path) !== $sourceSize) {
                return ['outcome' => 'failures', 'reason' => __('its size differs after copying')];
            }

            return [...$this->checked($file, $target, $sourceSha, $deep, 'copied'), 'bytes' => $sourceSize];
        } catch (Throwable $failure) {
            return ['outcome' => 'failures', 'reason' => __('it couldn’t be copied: :reason', ['reason' => BucketCheck::reason($failure)])];
        }
    }

    /**
     * Deletes one file's source copy after checking its target copy once
     * more: there, with the source's size. A file already gone from the
     * source counts as deleted when its target copy is there.
     *
     * @param  array{role: 'public'|'originals', path: string, bytes: int, sha256: string|null}  $file
     *
     * @return array{outcome: string, bytes?: int, reason?: string}
     */
    private function deleteFile(array $file, string $from, string $to): array
    {
        $source = self::disk(MediaLocations::disks($from)[$file['role']]);
        $target = self::disk(MediaLocations::disks($to)[$file['role']]);
        $path = $file['path'];

        try {
            $there = $target->fileExists($path);

            if (! $source->fileExists($path)) {
                return $there ? ['outcome' => 'present'] : ['outcome' => 'holes', 'reason' => __('missing in both locations')];
            }

            if (! $there || $target->fileSize($path) !== $source->fileSize($path)) {
                return ['outcome' => 'failures', 'reason' => __('its copy in “:to” is missing or a different size, so it was kept', ['to' => $to])];
            }

            $source->delete($path);

            return ['outcome' => 'deleted'];
        } catch (Throwable $failure) {
            return ['outcome' => 'failures', 'reason' => __('it couldn’t be deleted: :reason', ['reason' => BucketCheck::reason($failure)])];
        }
    }

    /**
     * The target's copy read back when it must be: an original against its
     * recorded SHA-256, any file with deep against the source's.
     *
     * @param  array{role: 'public'|'originals', path: string, bytes: int, sha256: string|null}  $file
     *
     * @return array{outcome: string, reason?: string}
     */
    private function checked(array $file, FilesystemAdapter $target, ?string $sourceSha, bool $deep, string $outcome): array
    {
        $expected = $file['sha256'] ?? ($deep ? $sourceSha : null);

        if ($expected === null) {
            return ['outcome' => $outcome];
        }

        return self::sha256($target, $file['path']) === $expected
            ? ['outcome' => $outcome]
            : ['outcome' => 'failures', 'reason' => __('the copy read back differs from the original')];
    }

    /**
     * The rows after $cursor, by ID, trashed ones too; with $since, only
     * those changed at or after it.
     *
     * @return list<object>
     */
    private static function chunk(?string $cursor, ?CarbonImmutable $since, int $size): array
    {
        return DB::table('media')
            ->select(['id', 'kind', 'original_path', 'size_bytes', 'sha256', 'variants'])
            ->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))
            ->when($since !== null, fn ($query) => $query->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->limit(max(1, $size))
            ->get()
            ->all();
    }

    /**
     * @param  list<'public'|'originals'>  $roles
     *
     * @return list<array{role: 'public'|'originals', path: string, bytes: int, sha256: string|null}>
     */
    private static function filesOf(object $row, array $roles): array
    {
        /** @var object{kind: string, original_path: string, size_bytes: int|string, sha256: string|null, variants: string|null} $row */
        return array_values(array_filter(MediaFiles::of($row), fn (array $file): bool => in_array($file['role'], $roles, true)));
    }

    private static function sha256(Filesystem $disk, string $path): ?string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private static function disk(string $name): FilesystemAdapter
    {
        return Storage::disk($name);
    }

    /**
     * One line after a chunk: items of the total, files copied, already
     * there, holes, failures, bytes and the rate.
     *
     * @param  array{items: int, copied: int, present: int, deleted: int, holes: int, failures: int, bytes: int}  $totals
     */
    private function progress(MediaMove $run, array $totals, float $began): string
    {
        $seconds = max(0.001, microtime(true) - $began);

        return __(':items of :total items: :copied copied, :present already there, :deleted deleted, :holes missing in both, :failures failed; :bytes bytes, :rate items a second.', [
            'items' => number_format($totals['items']),
            'total' => number_format($run->items_total),
            'copied' => number_format($totals['copied']),
            'present' => number_format($totals['present']),
            'deleted' => number_format($totals['deleted']),
            'holes' => number_format($totals['holes']),
            'failures' => number_format($totals['failures']),
            'bytes' => number_format($totals['bytes']),
            'rate' => number_format($totals['items'] / $seconds, 1),
        ]);
    }

    /**
     * The final line: verified or not, and what to do next.
     *
     * @param  array{items: int, copied: int, present: int, deleted: int, holes: int, failures: int, bytes: int}  $totals
     */
    private function ending(MediaMove $run, array $totals): string
    {
        $holes = $totals['holes'] === 0 ? '' : ' '.__(':holes files were missing in both locations; teme:media:move --status names them.', ['holes' => number_format($totals['holes'])]);

        if ($totals['failures'] > 0) {
            return __('Not verified: :failures files failed. teme:media:move --status names them; run the same command again to retry.', ['failures' => number_format($totals['failures'])]).$holes;
        }

        if ($run->kind === 'delete_source') {
            return __('Deleted the copies in “:from” of every file whose copy in “:to” was checked.', ['from' => $run->from_location, 'to' => $run->to_location]).$holes;
        }

        return __('Verified: every file the media rows name is in “:to”.', ['to' => $run->to_location]).$holes;
    }
}
