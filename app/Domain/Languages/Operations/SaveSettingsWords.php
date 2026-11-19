<?php

/**
 * Saves a settings group's "In other languages" table
 * (docs/proposals/multilingual-screens.md, 6): TranslateSettings once per
 * language, each on the version the last left, all in one transaction, so
 * one language refused keeps none of the others.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations;

use App\Domain\Languages\Operations\Input\SaveSettingsWordsInput;
use App\Domain\Settings\Operations\Input\TranslateSettingsInput;
use App\Domain\Settings\Operations\TranslateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class SaveSettingsWords extends BaseOperation implements RequiresElevation
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly TranslateSettings $translate,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        /** @var SaveSettingsWordsInput $input */
        return $this->registry->find($input->group)?->sensitive === true;
    }

    protected function authorize(OperationInput $input): void
    {
        /** @var SaveSettingsWordsInput $input */
        $definition = $this->registry->find($input->group);
        $actor = $input->actorId === null ? null : User::query()->find($input->actorId);

        if ($definition === null || $actor === null || ! $actor->can($definition->ability)) {
            throw new AuthorizationException('Not permitted to change these settings.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        // Each language's words are TranslateSettings' to check, inside the transaction.
    }

    /**
     * @return int the group's version after the last language
     */
    protected function perform(OperationInput $input): int
    {
        /** @var SaveSettingsWordsInput $input */
        $version = $input->expectedVersion;

        foreach ($input->words as $language => $values) {
            $version = $this->translate->execute(new TranslateSettingsInput($input->group, $language, $values, [], $version, $input->actorId))['version'];
        }

        return $version;
    }
}
