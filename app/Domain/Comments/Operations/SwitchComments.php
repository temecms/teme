<?php

/**
 * Switches comments on or off for the whole site (I-077, I-485; 1.8d's D3):
 * the `modules` group's switch, at the top of Settings › Discussion, changed
 * by whoever holds settings.modules.manage, against the version the form was
 * opened on. Every page is purged after commit, as the group declares (I-484).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\Operations\Input\SwitchCommentsInput;
use App\Domain\Settings\Modules;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class SwitchComments extends BaseOperation
{
    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SwitchCommentsInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('settings.modules.manage')) {
            throw new AuthorizationException('Not permitted to switch comments on or off.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SwitchCommentsInput $input */
        $setting = $this->registry->find('modules')?->setting(Modules::COMMENTS);
        $rules = $setting === null ? [] : SettingRules::for($setting);

        Validator::make(['values' => ['comments' => $input->value]], ['values.comments' => ['required', ...$rules]])->validate();
    }

    /**
     * @return array{on: bool, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SwitchCommentsInput $input */
        $was = Modules::on(Modules::COMMENTS);

        $this->writer->write(new SettingsWrite(
            group: 'modules',
            values: [Modules::COMMENTS => $input->value],
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));

        $on = $input->value === Modules::ON;

        return ['on' => $on, 'changed' => $on !== $was];
    }
}
