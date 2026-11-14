<?php

declare(strict_types=1);

namespace Tests\Fixtures\People;

use App\Domain\People\Credit;
use App\Domain\People\Operations\CreatePerson;
use App\Domain\People\Operations\Input\CreatePersonInput;
use App\Domain\People\Operations\Input\SetBylinesInput;
use App\Domain\People\Operations\SetBylines;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Closure;

/**
 * People and bylines for bylines' tests (I-3366), through the real
 * Operations as the operator: a guest with their own page, and an item's
 * credits in order.
 */
final class Credited
{
    /**
     * A person with no account, with their page; their ID.
     */
    public static function guest(string $name, string $slug, ?string $bio = null): string
    {
        return self::asOperator(fn (): string => app(CreatePerson::class)->execute(new CreatePersonInput(null, $name, $slug, $bio)));
    }

    /**
     * Sets an item's bylines to these credits, in order; none credits its
     * owner again. An account named gets its person, made then if it has none.
     *
     * @param  list<Credit>  $credits
     */
    public static function set(string $contentId, array $credits): void
    {
        self::asOperator(fn (): mixed => app(SetBylines::class)->execute(new SetBylinesInput(null, $contentId, array_map(fn (Credit $credit): string => $credit->key(), $credits))));
    }

    /**
     * Runs this as the operator, then puts back whoever was acting.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     *
     * @return T
     */
    private static function asOperator(Closure $run): mixed
    {
        $before = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            return $run();
        } finally {
            app()->instance(Elevation::class, $before);
        }
    }
}
