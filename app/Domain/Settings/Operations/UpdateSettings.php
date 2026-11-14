<?php

declare(strict_types=1);

namespace App\Domain\Settings\Operations;

use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingType;

/**
 * Changes one settings group. The person must hold the group's ability, a
 * sensitive group asks for a fresh password confirmation, and a form opened on
 * an older version is refused. Only changed values are stored, never defaults,
 * and every change is recorded.
 */
final class UpdateSettings extends BaseOperation implements RequiresElevation
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SecretStore $secrets,
        private readonly SettingsWriter $writer,
        private readonly Container $container,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        /** @var UpdateSettingsInput $input */
        return $this->registry->find($input->group)?->sensitive === true;
    }

    protected function authorize(OperationInput $input): void
    {
        /** @var UpdateSettingsInput $input */
        $definition = $this->registry->find($input->group);
        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());

        if ($definition === null || $actor === null || ! $actor->can($definition->ability)) {
            throw new AuthorizationException('Not permitted to change these settings.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateSettingsInput $input */
        $definition = $this->definition($input->group);

        // A group with its own screen has its own Operation, which checks
        // what the generic form cannot (Settings › Addresses).
        if ($definition->screen !== null) {
            throw ValidationException::withMessages(['group' => __('Change these settings on their own screen.')]);
        }

        $problems = [];

        foreach ($input->reset as $key) {
            if ($definition->setting($key) === null) {
                $problems['reset'] = "Cannot reset [{$key}]: it does not exist.";
            }
        }

        foreach (array_keys($input->secrets) as $key) {
            $setting = $definition->setting($key);

            if ($setting === null || $setting->type !== SettingType::Secret) {
                $problems["secrets.{$key}"] = 'This is not a secret setting.';
            } elseif ($this->secrets->isProvidedByEnvironment($input->group, $key)) {
                $problems["secrets.{$key}"] = 'This value is provided by the server and cannot be changed here.';
            }
        }

        // The checks a part adds to this group, beyond each type's own
        // (App\Domain\Settings\SettingsCheck).
        foreach ($this->container->tagged(SettingsCheck::TAG) as $check) {
            if ($check instanceof SettingsCheck && $check->group() === $input->group) {
                $problems += $check->problems($input->values);
            }
        }

        SettingRules::validateValues($definition, $input->values, $problems);
    }

    /**
     * @return array{group: string, version: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UpdateSettingsInput $input */
        $version = $this->writer->write(new SettingsWrite(
            group: $input->group,
            values: $input->values,
            reset: $input->reset,
            secrets: $input->secrets,
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
            origin: $input->origin,
        ));

        return ['group' => $input->group, 'version' => $version];
    }

    private function definition(string $group): SettingsGroup
    {
        return $this->registry->find($group) ?? throw new AuthorizationException('Not permitted to change these settings.');
    }
}
