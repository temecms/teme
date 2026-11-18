<?php

/**
 * teme:installer — the installer service (I-211): when it starts, checks
 * that the installed plugins and themes fit this image and rebuilds them
 * when they don't, then carries out the requests asked for from the admin,
 * one step a pass, as the operator. It alone writes the extensions folder.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Extensions\Installer\InstallerFailed;
use App\Domain\Extensions\Installer\InstallerRunner;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PDOException;
use Throwable;

final class RunInstaller extends Command
{
    /** The advisory lock a pass holds, so two installers never act at once. */
    public const string LOCK = 'teme.installer';

    /** The file the service's health check looks for once the start check has run. */
    public const string READY = '/tmp/teme-installer-ready';

    /** The most passes `--once` takes to carry one request as far as it goes. */
    private const int MOST_PASSES = 50;

    /** @var string */
    protected $signature = 'teme:installer
        {--once : Check the folder, carry out the open request as far as it goes, then stop}
        {--prepare-only : Check the installed plugins and themes against this image, then stop}';

    /** @var string */
    protected $description = 'Carry out the plugin and theme requests asked for from the admin; runs until stopped';

    private bool $stopping = false;

    private ?string $blocked = null;

    public function handle(): int
    {
        if ((string) config('teme.extensions.path') === '') {
            $this->components->error(__('This server has no extensions folder: set TEME_EXTENSIONS_PATH to it.'));

            return self::FAILURE;
        }

        $this->laravel->instance(Elevation::class, new OperatorElevation);

        // SIGINT and SIGTERM, where PHP has pcntl: the pass in hand ends first.
        $this->trap([2, 15], function (): void {
            $this->stopping = true;
        });

        $this->prepare();

        if ($this->option('prepare-only')) {
            return $this->blocked === null ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('once')) {
            for ($pass = 0; $pass < self::MOST_PASSES && ! $this->stopping; $pass++) {
                $this->pass();

                if (! $this->runner()->running()) {
                    break;
                }
            }

            return self::SUCCESS;
        }

        while (! $this->stopping) {
            $this->pass();

            if ($this->stopping) {
                break;
            }

            Sleep::for($this->wait())->seconds();
        }

        return self::SUCCESS;
    }

    /**
     * The start check, under the installer lock: the folder can be written,
     * and the current release fits this image or is rebuilt. Whatever its outcome, the ready file is
     * written, so the site's other roles start: on extensions held, if need be.
     */
    public function prepare(): void
    {
        try {
            // Waits for the lock, so a --once run beside the service never steps the same rebuild twice.
            while (! DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS got', [self::LOCK])->got) {
                if ($this->stopping) {
                    throw new InstallerFailed('The installer stopped while another installer held its lock.');
                }

                Sleep::for(1)->second();
            }

            try {
                $held = $this->runner()->prepare();
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [self::LOCK]);
            }

            $this->blocked = null;

            if ($held !== null) {
                $this->components->warn(__('The installed plugins and themes are held: :reason', ['reason' => $held]));
            }
        } catch (InstallerFailed $failure) {
            $this->blocked = $failure->getMessage();
            $this->components->error($failure->getMessage());
        } catch (Throwable $failure) {
            report($failure);
            $this->blocked = $failure->getMessage();
        } finally {
            @file_put_contents(self::READY, (string) time());
        }
    }

    /**
     * One pass: shows the installer is alive and, holding the lock, takes
     * one step of the open request. A failure is reported and the loop goes
     * on: the row is the state, and the next pass reads it again.
     */
    public function pass(): void
    {
        app(Heartbeats::class)->beat(Heartbeats::INSTALLER);

        if ($this->blocked !== null) {
            // The folder couldn't be written: look again, and take no request until it can.
            $this->prepare();

            if ($this->blocked !== null) {
                return;
            }
        }

        try {
            // Taken at the start of every pass, never kept: a reconnect would drop a kept lock without a word.
            if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS got', [self::LOCK])->got) {
                return;
            }

            try {
                $this->runner()->tick();
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [self::LOCK]);
            }
        } catch (Throwable $failure) {
            report($failure);

            if ($failure instanceof QueryException || $failure instanceof PDOException) {
                DB::disconnect();
            }
        }
    }

    /** The runner for this server's folder; a test binds its own. */
    private function runner(): InstallerRunner
    {
        return $this->laravel->bound(InstallerRunner::class) ? $this->laravel->make(InstallerRunner::class) : InstallerRunner::make();
    }

    /** Returns the seconds to wait before the next pass: 3 while a request runs, else 30. */
    public function wait(): int
    {
        return $this->runner()->running() ? 3 : 30;
    }
}
