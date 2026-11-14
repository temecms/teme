<?php

declare(strict_types=1);

namespace App\Domain\Preferences;

use InvalidArgumentException;

/**
 * Every per-person preference, declared once (I-110). Each declaration is
 * checked as it arrives, as SettingsRegistry checks settings: the key's
 * shape, no duplicate, no key that is both a leaf and the prefix of another,
 * a known type, and a default the declaration itself allows.
 */
final class PreferenceRegistry
{
    private const string KEY = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /** @var array<string, PreferenceDefinition> */
    private array $definitions = [];

    public function register(PreferenceDefinition $definition): void
    {
        $key = $definition->key;

        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("Preference key [{$key}] must be dot-separated lowercase words.");
        }

        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Preference [{$key}] is already declared.");
        }

        // `appearance` beside `appearance.mode` would make one key both a value
        // and a group of values.
        foreach (array_keys($this->definitions) as $existing) {
            if (str_starts_with($existing, $key.'.') || str_starts_with($key, $existing.'.')) {
                throw new InvalidArgumentException("Preference [{$key}] and [{$existing}] cannot both be declared: one is the prefix of the other.");
            }
        }

        if (! in_array($definition->type, PreferenceDefinition::TYPES, true)) {
            throw new InvalidArgumentException("Preference [{$key}] has an unknown type [{$definition->type}].");
        }

        if ($definition->type === 'list' && $definition->allowed === null) {
            throw new InvalidArgumentException("List preference [{$key}] needs the callback that says which values it allows.");
        }

        if (! $definition->allows($definition->default)) {
            throw new InvalidArgumentException("Preference [{$key}] has a default its own declaration does not allow.");
        }

        $this->definitions[$key] = $definition;
    }

    /**
     * The declaration with this key, or null when nothing declares it.
     */
    public function get(string $key): ?PreferenceDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * Every declaration, in the order they were registered.
     *
     * @return array<string, PreferenceDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
