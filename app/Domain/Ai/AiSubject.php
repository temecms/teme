<?php

/**
 * What one request is about, by IDs only, so a queued job carries no text
 * (I-320): the item or media item, the revision asked about, the target inside
 * it (a block's ID or a field's key), and the task's options, such as a preset
 * or a selection's offsets.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AiSubject
{
    /**
     * @param  array<string, string|int>  $options
     */
    public function __construct(
        public AiSubjectType $type,
        public string $id,
        public ?string $revisionId = null,
        public ?string $target = null,
        public array $options = [],
    ) {
        foreach ([$id, $revisionId] as $ulid) {
            if ($ulid !== null && ! Str::isUlid($ulid)) {
                throw new InvalidArgumentException("An AI subject's id [{$ulid}] isn't a ULID.");
            }
        }
    }
}
