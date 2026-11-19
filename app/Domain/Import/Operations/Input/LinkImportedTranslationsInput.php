<?php

/**
 * What LinkImportedTranslations needs: the source, whether the groups are
 * of posts and pages (`content`) or of terms (`term`), one batch of groups
 * as PolylangGroups gives them (WordPress IDs; a term's term ID), and who
 * is importing (null for the operator).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class LinkImportedTranslationsInput implements OperationInput
{
    /**
     * @param  list<list<string>>  $groups
     */
    public function __construct(
        public string $sourceId,
        public string $kind,
        public array $groups,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
