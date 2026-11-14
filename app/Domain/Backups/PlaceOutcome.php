<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticResult;

/**
 * What a run saw at one place: whether restic reached it, and the words for
 * what went wrong there, or none. EndBackupRun turns it into the place's
 * state, as the keys-and-places part does for a test of a place (I-360).
 */
final readonly class PlaceOutcome
{
    private function __construct(public bool $reached, public ?string $error) {}

    public static function answered(): self
    {
        return new self(true, null);
    }

    /**
     * restic's failure there. It reached the place when it said the key was
     * wrong or the backups were locked: the place answered.
     */
    public static function failed(ResticResult $result, string $place): self
    {
        $reached = in_array($result->outcome, [ResticOutcome::Ok, ResticOutcome::WrongKey, ResticOutcome::Locked], true);
        $words = $result->sentence($place);

        return new self($reached, $words === '' ? ResticOutcome::Failed->sentence($place) : $words);
    }

    public static function refused(string $sentence, bool $reached): self
    {
        return new self($reached, $sentence);
    }
}
