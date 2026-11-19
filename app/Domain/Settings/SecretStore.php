<?php

/**
 * Secrets are decrypted on demand and never cached. An environment variable,
 * when the declaration names one and the server sets it, wins. With config
 * cached, only real environment variables count — not the .env file.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Support\Env;
use InvalidArgumentException;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

final class SecretStore implements SecretReader
{
    public function __construct(private readonly SettingsRegistry $registry) {}

    public function secret(string $group, string $key): ?string
    {
        $setting = $this->declaration($group, $key);

        $fromEnvironment = $this->fromEnvironment($setting);

        if ($fromEnvironment !== null) {
            return $fromEnvironment;
        }

        $stored = $this->stored($group)[$key] ?? null;

        return is_string($stored) ? $stored : null;
    }

    /**
     * What a form may know about a secret: whether it is set, and where from.
     *
     * @return array{isSet: bool, source: 'database'|'environment'|null}
     */
    public function status(string $group, string $key): array
    {
        $setting = $this->declaration($group, $key);

        if ($this->fromEnvironment($setting) !== null) {
            return ['isSet' => true, 'source' => 'environment'];
        }

        $isSet = is_string($this->stored($group)[$key] ?? null);

        return ['isSet' => $isSet, 'source' => $isSet ? 'database' : null];
    }

    public function isProvidedByEnvironment(string $group, string $key): bool
    {
        return $this->fromEnvironment($this->declaration($group, $key)) !== null;
    }

    private function fromEnvironment(Setting $setting): ?string
    {
        if ($setting->env === null) {
            return null;
        }

        $value = Env::get($setting->env);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $group): array
    {
        // find(), not value(): the encrypted cast only runs on a model.
        return SettingsGroupRecord::query()->find($group)->secrets ?? [];
    }

    private function declaration(string $group, string $key): Setting
    {
        $setting = $this->registry->find($group)?->setting($key);

        if ($setting === null || $setting->type !== SettingType::Secret) {
            throw new InvalidArgumentException("[{$group}.{$key}] is not a secret setting.");
        }

        return $setting;
    }
}
