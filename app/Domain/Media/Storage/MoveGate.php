<?php

/**
 * Whether the source's copies may be deleted after a move (I-3456): only
 * once the site reads the target for every role moved, the latest finished
 * copy found no failure and began after that switch, an unbroken chain of
 * catch-ups leads back from it to a full copy with no failure, and no
 * backup or other move is working.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Storage;

use App\Domain\Backups\BackupLock;
use App\Domain\Media\MediaLocations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class MoveGate
{
    public function __construct(private BackupLock $backups, private MediaMoveLock $moves) {}

    /**
     * Why the source's copies may not be deleted now, in words; null when
     * they may. $holdingMoveLock says the caller already holds the move
     * lock, so it isn't another move's.
     *
     * @param  list<'public'|'originals'>  $roles
     */
    public function refusal(string $from, string $to, array $roles, bool $holdingMoveLock = true): ?string
    {
        $target = MediaLocations::disks($to);
        $site = ['public' => (string) config('media.disk'), 'originals' => (string) config('media.originals_disk')];

        foreach ($roles as $role) {
            if ($site[$role] !== $target[$role]) {
                return __('The site still uses “:disk” for its :role files: set MEDIA_STORAGE to the new location and deploy before deleting anything.', ['disk' => $site[$role], 'role' => $role === 'public' ? __('public') : __('original')]);
            }
        }

        $chain = $this->finishedCopies($from, $to, $roles);
        $latest = $chain[0] ?? null;

        if ($latest === null) {
            return __('No copy from “:from” to “:to” has finished. Copy first.', ['from' => $from, 'to' => $to]);
        }

        if ((int) $latest->failures > 0) {
            return __('The latest copy found :failures failures. Run it again until none fail.', ['failures' => number_format((int) $latest->failures)]);
        }

        $disks = (array) json_decode((string) $latest->site_disks, true);

        foreach ($roles as $role) {
            if (($disks[$role] ?? null) !== $target[$role]) {
                return __('The latest copy began before the site switched to “:to”. Run teme:media:move --catch-up now, then delete.', ['to' => $to]);
            }
        }

        if (! self::unbroken($chain)) {
            return __('The copies since the last full copy leave a gap, so a file changed in it may not have been copied. Run a full copy, then delete.');
        }

        if ($this->backupRunning()) {
            return __('A backup is running. Delete when it has finished.');
        }

        if (! $holdingMoveLock && $this->moves->isHeld()) {
            return __('Another media move is running. Wait for it to finish, or stop it, first.');
        }

        return null;
    }

    /**
     * Whether a backup, a prune or a check holds the backup lock now.
     */
    public function backupRunning(): bool
    {
        return $this->backups->isHeld();
    }

    /**
     * Whether each catch-up, newest first, began its changes at or before
     * the previous finished copy's start, every one without failures, back
     * to a full copy.
     *
     * @param  list<object>  $chain
     */
    private static function unbroken(array $chain): bool
    {
        foreach ($chain as $index => $run) {
            if ((int) $run->failures > 0) {
                return false;
            }

            if ($run->scope === 'all') {
                return true;
            }

            $previous = $chain[$index + 1] ?? null;

            if ($previous === null || $run->changed_since === null
                || CarbonImmutable::parse((string) $run->changed_since)->greaterThan(CarbonImmutable::parse((string) $previous->started_at))) {
                return false;
            }
        }

        return false;
    }

    /**
     * Every finished copy in this direction for these roles, newest first.
     *
     * @param  list<'public'|'originals'>  $roles
     *
     * @return list<object>
     */
    private function finishedCopies(string $from, string $to, array $roles): array
    {
        $sorted = $roles;
        sort($sorted);

        return DB::table('media_moves')
            ->where(['kind' => 'copy', 'from_location' => $from, 'to_location' => $to, 'state' => 'finished'])
            ->whereRaw('roles = ?::jsonb', [json_encode($sorted, JSON_THROW_ON_ERROR)])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get(['id', 'scope', 'changed_since', 'failures', 'site_disks', 'started_at'])
            ->all();
    }
}
