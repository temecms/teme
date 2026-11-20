<?php

/**
 * A plugin's door for what visitors send from its public forms: each is kept
 * as an item waiting for review, written as the site itself.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * A plugin's door for what visitors send from its public forms, offered when
 * it was granted content.submissions. Each submission is kept as an item
 * waiting for review, in the In review list: written as the site itself, not
 * as a person, so it has no author; marked as a submission; and never
 * published by this door. A person reviews it and publishes, returns or
 * trashes it as any other item. Teme limits how many it takes in an hour,
 * from one visitor address and through one plugin.
 *
 * @since 1.3.0
 */
interface Submissions
{
    /**
     * Keeps what the visitor sent, and returns the item, its status review
     * and $submission true.
     *
     * @throws WriteRefused when the submission is refused: too many this hour,
     *                      a type or term the site doesn't have, or a body or field
     *                      that isn't valid; nothing was written
     *
     * @since 1.3.0
     */
    public function submit(Submission $submission): ItemRef;
}
