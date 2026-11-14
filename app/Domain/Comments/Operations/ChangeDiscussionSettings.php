<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\Operations\Input\ChangeDiscussionSettingsInput;
use App\Domain\Settings\Modules;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Changes the Discussion settings on Settings › Discussion (1.8d's D3): whoever
 * holds settings.discussion.manage, against the version the form was opened
 * on, each value checked as the generic screen checks it. While comments are
 * off it changes nothing, so the settings the page hides stay as they were (E1).
 */
final class ChangeDiscussionSettings extends BaseOperation
{
    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ChangeDiscussionSettingsInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('settings.discussion.manage')) {
            throw new AuthorizationException('Not permitted to change these settings.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeDiscussionSettingsInput $input */
        if (! Modules::on(Modules::COMMENTS)) {
            throw ValidationException::withMessages(['values' => __('Comments are switched off.')]);
        }

        $group = $this->registry->find('discussion') ?? throw new RuntimeException('No settings group [discussion] is registered.');

        SettingRules::validateValues($group, $input->values);
    }

    protected function perform(OperationInput $input): int
    {
        /** @var ChangeDiscussionSettingsInput $input */
        return $this->writer->write(new SettingsWrite(
            group: 'discussion',
            values: $input->values,
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));
    }
}
