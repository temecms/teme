<?php

declare(strict_types=1);

namespace App\Domain\Settings\Operations;

use App\Domain\Plugins\HostPattern;
use App\Domain\Plugins\Operations\AgreePluginHosts;
use App\Domain\Plugins\Operations\Input\AgreePluginHostsInput;
use App\Domain\Plugins\SettingHosts;
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
 * and every change is recorded. A plugin's setting that names a host it may
 * not reach yet is saved only when the person agrees to that host, after
 * their password (docs/plans/plugin-sockets-1.md, A2).
 */
final class UpdateSettings extends BaseOperation implements RequiresElevation
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SecretStore $secrets,
        private readonly SettingsWriter $writer,
        private readonly Container $container,
        private readonly SettingHosts $settingHosts,
        private readonly AgreePluginHosts $agreeHosts,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        /** @var UpdateSettingsInput $input */
        return $this->registry->find($input->group)?->sensitive === true || $input->agreedHosts !== [];
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

        $plugin = $this->settingHosts->forGroup($input->group);

        if ($plugin !== null && $problems === []) {
            $problems += $this->unagreedHosts($plugin, $input);
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * Where a plugin's `hostsFrom` setting names a host it may not reach
     * yet that the person didn't agree to: the refusal, under the first
     * such field.
     *
     * @param  array{name: string, label: string, keys: list<string>, agreed: list<string>, hosts: list<string>}  $plugin
     *
     * @return array<string, string>
     */
    private function unagreedHosts(array $plugin, UpdateSettingsInput $input): array
    {
        $missing = [];
        $field = null;

        foreach ($plugin['keys'] as $key) {
            if (! array_key_exists($key, $input->values)) {
                continue;
            }

            $new = array_filter(
                array_diff(SettingHosts::hostsIn($this->normalised($input, [$key]), [$key]), $plugin['agreed'], $input->agreedHosts),
                // A host its manifest's agreed hosts cover is reachable already.
                fn (string $host): bool => ! HostPattern::anyCovers($plugin['hosts'], $host),
            );

            if ($new !== []) {
                $field ??= $key;
                array_push($missing, ...$new);
            }
        }

        if ($field === null) {
            return [];
        }

        return ["values.{$field}" => __('Agree to let :plugin connect to :hosts, or remove them.', [
            'plugin' => $plugin['label'],
            'hosts' => implode(', ', array_values(array_unique($missing))),
        ])];
    }

    /**
     * The new hosts this save names that the person agreed to, for the
     * plugin whose settings these are.
     *
     * @return list<string>
     */
    private function agreedNow(UpdateSettingsInput $input): array
    {
        $plugin = $input->agreedHosts === [] ? null : $this->settingHosts->forGroup($input->group);

        if ($plugin === null) {
            return [];
        }

        $keys = array_values(array_filter($plugin['keys'], fn (string $key): bool => array_key_exists($key, $input->values)));
        $named = SettingHosts::hostsIn($this->normalised($input, $keys), $keys);

        return array_values(array_intersect(array_diff($named, $plugin['agreed']), $input->agreedHosts));
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

        $agreed = $this->agreedNow($input);
        $plugin = $agreed === [] ? null : $this->settingHosts->forGroup($input->group);

        if ($plugin !== null) {
            $this->agreeHosts->execute(new AgreePluginHostsInput($plugin['name'], $agreed, $input->actorId()));
        }

        return ['group' => $input->group, 'version' => $version];
    }

    /**
     * These keys' values as they would be stored: a urls setting's lines as a list.
     *
     * @param  list<string>  $keys
     *
     * @return array<string, mixed>
     */
    private function normalised(UpdateSettingsInput $input, array $keys): array
    {
        $definition = $this->definition($input->group);
        $values = [];

        foreach ($keys as $key) {
            $setting = $definition->setting($key);

            if ($setting !== null && array_key_exists($key, $input->values)) {
                $values[$key] = SettingRules::normalise($setting, $input->values[$key]);
            }
        }

        return $values;
    }

    private function definition(string $group): SettingsGroup
    {
        return $this->registry->find($group) ?? throw new AuthorizationException('Not permitted to change these settings.');
    }
}
