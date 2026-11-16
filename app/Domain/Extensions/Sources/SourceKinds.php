<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Every kind of package source: Packagist, which is always there, and each
 * kind registered under the container tag. Read through the container each
 * time, so it holds no state.
 */
final readonly class SourceKinds
{
    /** The container tag a source kind is registered under. */
    public const string TAG = 'teme.extensions.sources';

    public function __construct(private Container $container) {}

    /**
     * Every kind by its key, Packagist first, then in the order registered.
     *
     * @return array<string, SourceKind>
     */
    public function all(): array
    {
        $kinds = [PackagistSource::KEY => new PackagistSource];

        foreach ($this->container->tagged(self::TAG) as $kind) {
            if (! $kind instanceof SourceKind) {
                throw new UnexpectedValueException('Registered as a package source, but '.get_debug_type($kind).' isn\'t one.');
            }

            $key = $kind->key();

            if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) !== 1) {
                throw new UnexpectedValueException("A package source's key must be 2 to 32 lowercase letters, digits or underscores; \"{$key}\" isn't.");
            }

            if (isset($kinds[$key])) {
                throw new UnexpectedValueException("Two package sources are registered as \"{$key}\".");
            }

            $kinds[$key] = $kind;
        }

        return $kinds;
    }

    /**
     * Returns every kind's key, Packagist first.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** Returns the kind with this key, or null when none is registered. */
    public function find(string $key): ?SourceKind
    {
        return $this->all()[$key] ?? null;
    }

    /** Returns the kind with this key; throws when none is registered. */
    public function get(string $key): SourceKind
    {
        return $this->find($key) ?? throw new InvalidArgumentException("No package source is registered as \"{$key}\".");
    }
}
