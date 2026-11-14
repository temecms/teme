<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

/**
 * How a restic call ended, by restic's own exit statuses, and the two Teme
 * adds: timed out, and not started. Anything but Ok is a failure,
 * Incomplete included (I-366).
 */
enum ResticOutcome
{
    case Ok;
    case Failed;
    case Crashed;
    case Incomplete;
    case NoRepository;
    case Locked;
    case WrongKey;
    case Cancelled;
    case TimedOut;
    case NotStarted;

    /**
     * What restic's exit status means. A status its documentation doesn't
     * name is a failure, as the documentation says it must be.
     */
    public static function fromExitCode(int $code): self
    {
        return match ($code) {
            0 => self::Ok,
            2 => self::Crashed,
            // A backup that couldn't read some files, or a prune that couldn't remove some snapshots.
            3 => self::Incomplete,
            10 => self::NoRepository,
            11 => self::Locked,
            12 => self::WrongKey,
            130 => self::Cancelled,
            default => self::Failed,
        };
    }

    /**
     * What went wrong at $place, in words people read; empty for Ok.
     */
    public function sentence(string $place, int $timeoutSeconds = 0): string
    {
        $minutes = max(1, (int) ceil($timeoutSeconds / 60));

        return match ($this) {
            self::Ok => '',
            self::Failed => "The backup tool couldn’t finish at {$place}.",
            self::Crashed => "The backup tool crashed at {$place}.",
            self::Incomplete => "Restic couldn’t finish everything at {$place}: some files couldn’t be read, or some old backups couldn’t be removed.",
            self::NoRepository => "Teme found no backups at {$place}. Check its address, and that the storage is still there.",
            self::Locked => "The backups at {$place} were locked by another task, so this didn’t run.",
            self::WrongKey => "The key didn’t open the backups at {$place}.",
            self::Cancelled => "The backup tool was stopped at {$place} before it finished.",
            self::TimedOut => "The backup tool took longer than {$minutes} ".($minutes === 1 ? 'minute' : 'minutes')." at {$place}, so Teme stopped it.",
            self::NotStarted => 'Teme couldn’t start restic. Run php artisan teme:backup:tools at the server to see why.',
        };
    }
}
