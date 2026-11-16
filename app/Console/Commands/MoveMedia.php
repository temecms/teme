<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Storage\MediaMover;
use App\Domain\Media\Storage\MediaMoveRefused;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `teme:media:move`: the operator's copy of a media library from one
 * location to another, resumable and verified, and later the deletion of
 * the source's copies (I-3456). It works in this process, as the operator.
 */
final class MoveMedia extends Command
{
    /** @var string */
    protected $signature = 'teme:media:move
        {--from=server : The location the files are in now}
        {--to=bucket : The location to copy them to}
        {--only= : public or originals, for a site that keeps one role where it is}
        {--catch-up : Only items changed since the last finished copy in this direction}
        {--deep : Read back every copied file and compare SHA-256, not only the originals}
        {--chunk=100 : Media items per chunk}
        {--dry-run : Count the items, files and bytes the rows name, and copy nothing}
        {--status : List the last ten moves and stop}';

    /** @var string */
    protected $description = 'Copy the media library between the server and object storage, resumable and verified';

    private bool $stopping = false;

    public function handle(MediaMover $mover): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        if ((bool) $this->option('status')) {
            return $this->status();
        }

        [$from, $to] = [(string) $this->option('from'), (string) $this->option('to')];
        $only = (string) $this->option('only');

        if (! in_array($only, ['', 'public', 'originals'], true)) {
            $this->error(__('--only takes public or originals.'));

            return MediaMover::REFUSED;
        }

        /** @var list<'public'|'originals'> $roles */
        $roles = $only === '' ? ['originals', 'public'] : [$only];

        if ((bool) $this->option('dry-run')) {
            return $this->dryRun($mover, $from, $to, $roles);
        }

        // Finishes the item in hand, records the chunk and ends the run stopped.
        $this->trap([2, 15], function (): void {
            $this->stopping = true;
        });

        // A bar only where a person watches: in a log it would be noise.
        $bar = $this->output->isDecorated() ? $this->output->createProgressBar() : null;
        $result = $mover->copy(
            $from,
            $to,
            $roles,
            (bool) $this->option('catch-up'),
            (bool) $this->option('deep'),
            max(1, (int) $this->option('chunk')),
            function (string $line) use ($bar): void {
                $bar?->clear();
                $this->line($line);
                $bar?->display();
            },
            fn (int $items) => $bar?->advance($items),
            fn (): bool => $this->stopping,
        );
        $bar?->clear();

        match ($result['code']) {
            MediaMover::VERIFIED => $this->info($result['sentence']),
            MediaMover::STOPPED => $this->warn($result['sentence']),
            default => $this->error($result['sentence']),
        };

        return $result['code'];
    }

    /**
     * @param  list<'public'|'originals'>  $roles
     */
    private function dryRun(MediaMover $mover, string $from, string $to, array $roles): int
    {
        try {
            MediaMover::refuse($from, $to, $roles);
            $since = null;

            if ((bool) $this->option('catch-up')) {
                $since = MediaMover::catchUpSince($from, $to, $roles)
                    ?? throw new MediaMoveRefused(__('There is no finished copy from “:from” to “:to” to catch up after. Run a full copy first, without --catch-up.', ['from' => $from, 'to' => $to]));
            }
        } catch (MediaMoveRefused $refused) {
            $this->error($refused->getMessage());

            return MediaMover::REFUSED;
        }

        $counted = $mover->count($roles, $since);
        $this->info(__('A move from “:from” to “:to” would cover :items media items, :files files, :bytes bytes. Nothing was copied.', [
            'from' => $from,
            'to' => $to,
            'items' => number_format($counted['items']),
            'files' => number_format($counted['files']),
            'bytes' => number_format($counted['bytes']),
        ]));

        return MediaMover::VERIFIED;
    }

    private function status(): int
    {
        $runs = DB::table('media_moves')->orderByDesc('started_at')->orderByDesc('id')->limit(10)->get();

        if ($runs->isEmpty()) {
            $this->line(__('No media has been moved yet.'));

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $this->line(__(':started  :kind :from → :to (:roles, :scope)  :state  :done of :total items, :copied copied, :present already there, :deleted deleted, :holes missing in both, :failures failed', [
                'started' => (string) $run->started_at,
                'kind' => $run->kind === 'copy' ? 'copy' : 'delete-source',
                'from' => $run->from_location,
                'to' => $run->to_location,
                'roles' => implode(' and ', (array) json_decode((string) $run->roles, true)),
                'scope' => $run->scope === 'all' ? __('all') : __('changed since :since', ['since' => (string) $run->changed_since]),
                'state' => $run->state,
                'done' => number_format((int) $run->items_done),
                'total' => number_format((int) $run->items_total),
                'copied' => number_format((int) $run->files_copied),
                'present' => number_format((int) $run->files_present),
                'deleted' => number_format((int) $run->files_deleted),
                'holes' => number_format((int) $run->holes),
                'failures' => number_format((int) $run->failures),
            ]));

            foreach ((array) json_decode((string) $run->problems, true) as $problem) {
                $this->line('    '.$problem['role'].':'.$problem['path'].' — '.$problem['reason']);
            }
        }

        return self::SUCCESS;
    }
}
