<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\Updater\PlatformCheck;
use App\Domain\Updates\Updater\UpdateRunner;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PDOException;
use Throwable;

/**
 * teme:updater — carries out the update asked for from the admin, through the platform, as the operator
 * (I-3326): it looks for a request every 30 seconds, every 5 while one runs, and takes the lock each pass, so
 * two updaters never act at once.
 */
final class RunUpdater extends Command
{
    /** The advisory lock a pass holds, so the updater that replaces this one and this one never both act. */
    public const string LOCK = 'teme.updater';

    /** @var string */
    protected $signature = 'teme:updater {--once : Run one pass, then stop}';

    /** @var string */
    protected $description = 'Carry out the update asked for from the admin, through the platform; runs until stopped';

    private bool $stopping = false;

    public function handle(): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        // SIGINT and SIGTERM, where PHP has pcntl: the pass in hand ends first.
        $this->trap([2, 15], function (): void {
            $this->stopping = true;
        });

        while (true) {
            $this->pass();

            if ($this->option('once') || $this->stopping) {
                return self::SUCCESS;
            }

            Sleep::for($this->wait())->seconds();
        }
    }

    /**
     * One pass: shows the updater is alive, and, holding the lock, takes one step of the open update. A
     * failure is reported and the loop goes on: the row is the state, and the next pass reads it again.
     */
    public function pass(): void
    {
        app(Heartbeats::class)->beat(Heartbeats::UPDATER);

        try {
            // Taken at the start of every pass, never kept: a reconnect would drop a kept lock without a word.
            if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS got', [self::LOCK])->got) {
                return;
            }

            try {
                app(UpdateRunner::class)->tick();

                // Between updates only: Settings › Updates says whether the Dokploy key works.
                if (! app(UpdateRunner::class)->running()) {
                    app(PlatformCheck::class)->runIfDue();
                }
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [self::LOCK]);
            }
        } catch (Throwable $failure) {
            report($failure);

            if ($failure instanceof QueryException || $failure instanceof PDOException) {
                // The next pass reconnects.
                DB::disconnect();
            }
        }
    }

    /**
     * Returns the seconds to wait before the next pass: 5 while an update runs, else 30.
     */
    public function wait(): int
    {
        return app(UpdateRunner::class)->running() ? 5 : 30;
    }
}
