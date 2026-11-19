<?php

/**
 * What one job of a step did: its messages for the run, and the cursor the next job starts at, or none when the step is done.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use InvalidArgumentException;

final readonly class StepResult
{
    /**
     * @param  list<ImportMessage>  $messages
     */
    private function __construct(
        public ?string $cursor,
        public array $messages,
    ) {}

    /**
     * The step is finished.
     *
     * @param  list<ImportMessage>  $messages
     */
    public static function done(array $messages = []): self
    {
        return new self(null, $messages);
    }

    /**
     * The step goes on in another job, starting at this cursor.
     *
     * @param  list<ImportMessage>  $messages
     */
    public static function continueAt(string $cursor, array $messages = []): self
    {
        if ($cursor === '' || mb_strlen($cursor) > 200) {
            throw new InvalidArgumentException("A step's cursor is 1 to 200 characters.");
        }

        return new self($cursor, $messages);
    }
}
