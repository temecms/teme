<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Turns a restic call that failed at a place into the words a person
 * reads, and leaves restic's own words, its secrets hidden, in the log for
 * whoever runs the server.
 */
final class PlaceFailure
{
    public static function from(ResticResult $result, string $place, string $doing): ValidationException
    {
        Log::warning("Backups: {$doing} at {$place}: {$result->detail()}");

        // restic said it succeeded, but not what Teme needed to read.
        $words = $result->succeeded() ? ResticOutcome::Failed->sentence($place) : $result->sentence($place);

        return ValidationException::withMessages(['place' => $words]);
    }
}
