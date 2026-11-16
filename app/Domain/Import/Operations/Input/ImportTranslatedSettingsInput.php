<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportTranslatedSettings needs: the settings group, the other
 * language whose words the old site had, those words by key, and who is
 * importing (null for the operator).
 */
final readonly class ImportTranslatedSettingsInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values  translatable key => that language's value
     */
    public function __construct(
        public string $group,
        public string $language,
        public array $values,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
