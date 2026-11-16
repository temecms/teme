<?php

declare(strict_types=1);

namespace App\Domain\Media\Storage;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * The lock one media move holds while it works (I-3456), so two never copy
 * or delete at once: a session-level PostgreSQL advisory lock on a
 * connection of its own, as the backup lock is, seen by every process and
 * let go when the work ends or the process dies.
 */
final class MediaMoveLock
{
    /** The lock's name, which PostgreSQL's hashtext() turns into its key. */
    public const string KEY = 'teme.media-move';

    /** The connection the lock is held on, made when it is taken. */
    public const string CONNECTION = 'teme_media_move_lock';

    private bool $holding = false;

    /**
     * Runs $work holding the lock, and lets go when the work ends or throws.
     * Never waits: while another process holds it, the move is refused.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     *
     * @return T
     *
     * @throws MediaMoveRefused
     */
    public function during(Closure $work): mixed
    {
        if ($this->holding) {
            throw new LogicException('This process already holds the media move lock.');
        }

        try {
            if (! (bool) $this->session()->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS taken', [self::KEY])->taken) {
                throw new MediaMoveRefused(__('Another media move is running. Wait for it to finish, or stop it, first.'));
            }

            $this->holding = true;

            return $work();
        } finally {
            $this->holding = false;

            try {
                $session = DB::connection(self::CONNECTION);

                if ($session->transactionLevel() > 0) {
                    $session->rollBack(0);
                }

                $session->select('SELECT pg_advisory_unlock_all()');
            } catch (Throwable) {
                // A broken session can't ask; closing it lets go instead.
            }

            DB::purge(self::CONNECTION);
        }
    }

    /**
     * Whether a move holds the lock now, in this process or any other.
     * Asking takes the shared lock for an instant and lets go.
     */
    public function isHeld(): bool
    {
        if ($this->holding) {
            return true;
        }

        $free = (bool) DB::selectOne('SELECT pg_try_advisory_lock_shared(hashtext(?)) AS free', [self::KEY])->free;

        if ($free) {
            DB::select('SELECT pg_advisory_unlock_shared(hashtext(?))', [self::KEY]);
        }

        return ! $free;
    }

    private function session(): ConnectionInterface
    {
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();

        return DB::connectUsing(self::CONNECTION, [...$config, 'name' => self::CONNECTION], force: true);
    }
}
