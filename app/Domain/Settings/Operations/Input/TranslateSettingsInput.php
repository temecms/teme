<?php

declare(strict_types=1);

namespace App\Domain\Settings\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `TranslateSettings` needs: the group, the other language whose words
 * are written, the words to set and the keys to return to the default
 * language's, the group's version the form was opened on, and who is asking.
 */
final readonly class TranslateSettingsInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values  translatable key => that language's value
     * @param  list<string>  $reset  translatable keys to return to the default language's value
     */
    public function __construct(
        public string $group,
        public string $language,
        public array $values,
        public array $reset,
        public int $expectedVersion,
        public ?string $actorId,
        public string $origin = 'human',
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
