<?php

declare(strict_types=1);

namespace App\Domain\Api;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * The API has nothing to give: no live, public thing at that ID or address,
 * or the API is switched off. Carries its own code and words, which a
 * problem gives as they are; a thing a reader can't see is answered
 * exactly as one that never existed (I-1700). A reader's miss, never
 * reported, so asking in a loop writes nothing to the log.
 */
final class ApiNotFound extends RuntimeException implements ShouldntReport
{
    private function __construct(public readonly ProblemCode $problem, public readonly string $detail)
    {
        parent::__construct($detail);
    }

    /**
     * The API is switched off (api_off).
     */
    public static function off(): self
    {
        return new self(ProblemCode::ApiOff, ApiWords::off());
    }

    /**
     * No live, public item has this ID.
     */
    public static function item(string $id): self
    {
        return new self(ProblemCode::NotFound, ApiWords::noItem($id));
    }

    /**
     * No live, public item has this address, current or old.
     */
    public static function path(string $path): self
    {
        return new self(ProblemCode::NotFound, ApiWords::noItemAt($path));
    }

    /**
     * No term of a public taxonomy has this ID.
     */
    public static function term(string $id): self
    {
        return new self(ProblemCode::NotFound, ApiWords::noTerm($id));
    }

    /**
     * No live, public item the API shows uses a file with this ID.
     */
    public static function file(string $id): self
    {
        return new self(ProblemCode::NotFound, ApiWords::noFile($id));
    }

    /**
     * Nothing of the API's is at this address.
     */
    public static function nothing(string $path): self
    {
        return new self(ProblemCode::NotFound, ApiWords::nothingAt($path));
    }
}
