<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * What became of a source object in the run that last touched it (I-345):
 * one outcome per object, in the report and its CSV.
 */
enum ImportOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Failed = 'failed';

    /**
     * The words people read for this outcome.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }

    /**
     * The state an object's map row keeps after this outcome.
     */
    public function status(): ImportStatus
    {
        return match ($this) {
            self::Created, self::Updated => ImportStatus::Imported,
            self::Skipped => ImportStatus::Skipped,
            self::Failed => ImportStatus::Failed,
        };
    }
}
