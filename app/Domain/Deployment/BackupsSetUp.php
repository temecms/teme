<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\Backups\Queries\BackupPlaces;
use Illuminate\Database\QueryException;

/**
 * Whether backups are set up, as a deploy and the doctor ask before
 * migrating (I-2401): false where the backup tables don't exist yet, on a
 * new database or a site from before them, instead of failing.
 */
final readonly class BackupsSetUp
{
    /** PostgreSQL's undefined table. */
    private const string NO_TABLE = '42P01';

    public function __construct(private BackupPlaces $places) {}

    public function now(): bool
    {
        try {
            return $this->places->isSetUp();
        } catch (QueryException $failed) {
            if ((string) $failed->getCode() === self::NO_TABLE) {
                return false;
            }

            throw $failed;
        }
    }
}
