<?php

declare(strict_types=1);

namespace Teme\Contracts\People;

/**
 * One page of PeopleReader::writers(): its people, and the cursor for the
 * next page.
 *
 * @since 1.0.0
 */
final readonly class PersonPage
{
    /**
     * @param  list<PersonRef>  $people
     * @param  string|null  $next  the cursor for the next page; null on the last page
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $people,
        public ?string $next,
    ) {}
}
