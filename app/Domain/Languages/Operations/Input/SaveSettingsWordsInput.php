<?php

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A settings group's words in each of the site's other languages, as the
 * "In other languages" table sends them, the group's version the form was
 * opened on, and who asks.
 */
final readonly class SaveSettingsWordsInput implements OperationInput
{
    /**
     * @param  array<string, array<string, string>>  $words  language tag => translatable key => its words ('' falls back)
     */
    public function __construct(
        public string $group,
        public array $words,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
