<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

/**
 * What teme:backup:tools reports: restic, pg_dump and pg_restore as found, in that order, the database server's version, and what is wrong in words; ready when nothing is.
 */
final readonly class BackupToolsReport
{
    /**
     * @param  list<ToolCheck>  $tools
     * @param  list<string>  $problems
     */
    public function __construct(
        public array $tools,
        public string $serverVersion,
        public array $problems,
    ) {}

    /**
     * Whether restic and PostgreSQL's tools will all do.
     */
    public function ready(): bool
    {
        return $this->problems === [];
    }
}
