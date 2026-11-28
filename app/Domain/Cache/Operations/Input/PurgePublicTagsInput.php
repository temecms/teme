<?php

/**
 * What PurgePublicTags needs: the page-cache tags to purge. A deferred job
 * acts for nobody, so there is no actor.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class PurgePublicTagsInput implements OperationInput
{
    /** @param  list<string>  $tags  the tags to purge */
    public function __construct(public array $tags) {}

    public function actorId(): ?string
    {
        return null;
    }
}
