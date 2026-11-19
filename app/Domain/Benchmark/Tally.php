<?php

/**
 * What one inspected run of a scenario sent beyond its SELECTs: the
 * statements that write, a failed one included, and the commands to the
 * page store's Valkey (D6). Laravel's query log records a statement only
 * once it has run, never a refused lock's INSERT, so writes are counted as
 * they are sent.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark;

use Closure;
use Illuminate\Support\Facades\DB;

final class Tally
{
    private bool $counting = false;

    private bool $aside = false;

    private int $writes = 0;

    private int $commands = 0;

    /**
     * Counts afresh, for one inspected run.
     */
    public function start(): void
    {
        $this->counting = true;
        $this->writes = 0;
        $this->commands = 0;
    }

    /**
     * Stops counting; what was counted stays readable.
     */
    public function stop(): void
    {
        $this->counting = false;
    }

    /**
     * Counts a statement about to be sent, when it writes: anything but a
     * read, a SET or an EXPLAIN, as the runner's own count of queries has it.
     */
    public function statement(string $sql): void
    {
        if ($this->counting && ! $this->aside && preg_match('/^\s*(select|with|set|explain)\b/i', $sql) !== 1) {
            $this->writes++;
        }
    }

    /**
     * Counts a command sent to the page store's Valkey.
     */
    public function command(): void
    {
        if ($this->counting && ! $this->aside) {
            $this->commands++;
        }
    }

    /**
     * Runs work that belongs to someone else, such as another request
     * storing the page a wait is waiting for (D9): nothing it sends is
     * counted, or kept in the query log.
     */
    public function aside(Closure $work): void
    {
        $logging = DB::logging();
        DB::disableQueryLog();
        $this->aside = true;

        try {
            $work();
        } finally {
            $this->aside = false;

            if ($logging) {
                DB::enableQueryLog();
            }
        }
    }

    /**
     * The statements that wrote since start().
     */
    public function writes(): int
    {
        return $this->writes;
    }

    /**
     * The commands sent to the page store's Valkey since start().
     */
    public function commands(): int
    {
        return $this->commands;
    }
}
