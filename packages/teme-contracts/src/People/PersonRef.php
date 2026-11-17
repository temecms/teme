<?php

declare(strict_types=1);

namespace Teme\Contracts\People;

/**
 * One person as a plugin may know them: their account ID and display name.
 *
 * @since 1.0.0
 */
final readonly class PersonRef
{
    /**
     * @param  string  $id  the account ID, as ContentWriter's credits and the person setting take it
     * @param  string  $name  the name the site shows for them
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
