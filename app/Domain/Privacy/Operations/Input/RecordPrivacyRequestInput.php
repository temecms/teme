<?php

/**
 * What RecordPrivacyRequest needs: export or erase, the address the request
 * is about, the operator's note on why and how they checked who asked,
 * whether to email the person when it ends, and who is asking (null for
 * the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RecordPrivacyRequestInput implements OperationInput
{
    public function __construct(
        public string $kind,
        public string $email,
        public string $note,
        public bool $reply = true,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Returns the address as the request keeps it: trimmed and lower-cased.
     */
    public function address(): string
    {
        return mb_strtolower(trim($this->email));
    }

    /**
     * Returns the note as the request keeps it: trimmed.
     */
    public function trimmedNote(): string
    {
        return trim($this->note);
    }
}
