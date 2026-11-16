<?php

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * The site's other languages in their new order, the languages' version the
 * form was opened on, and who asks. The default language stays first and
 * is not named.
 */
final readonly class ReorderSiteLanguagesInput implements OperationInput
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public array $tags,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
