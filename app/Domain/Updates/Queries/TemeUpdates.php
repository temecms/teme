<?php

declare(strict_types=1);

namespace App\Domain\Updates\Queries;

use App\Domain\Updates\Requests\TemeUpdate;
use App\Domain\Updates\Requests\UpdateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The update requests (I-3326), read from teme_updates: the one that is open, one by its ID, and the one the screen shows.
 */
final readonly class TemeUpdates
{
    /** Days a finished update stays at the top of Tools › Updates. */
    public const int SHOWN_DAYS = 7;

    /**
     * Returns the request that waits or runs, or null when none does.
     */
    public function open(): ?TemeUpdate
    {
        $row = DB::table('teme_updates')->whereIn('status', [UpdateStatus::Requested->value, UpdateStatus::Running->value])->first();

        return $row === null ? null : TemeUpdate::fromRow($row);
    }

    /**
     * Returns the request with this ID, or null.
     */
    public function find(string $id): ?TemeUpdate
    {
        $row = DB::table('teme_updates')->where('id', $id)->first();

        return $row === null ? null : TemeUpdate::fromRow($row);
    }

    /**
     * Returns the open request, else the newest that ended within seven days and wasn't cancelled, else null.
     */
    public function latestShown(): ?TemeUpdate
    {
        $open = $this->open();

        if ($open !== null) {
            return $open;
        }

        $row = DB::table('teme_updates')
            ->where('status', '<>', UpdateStatus::Cancelled->value)
            ->where('finished_at', '>=', CarbonImmutable::now()->subDays(self::SHOWN_DAYS))
            ->orderByDesc('finished_at')
            ->first();

        return $row === null ? null : TemeUpdate::fromRow($row);
    }
}
