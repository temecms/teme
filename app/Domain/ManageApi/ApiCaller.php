<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

use App\Domain\ManageApi\Models\ApiKey;
use App\Models\User;

/**
 * Who is calling the management API: the key, by ID and name, the person
 * it acts as and its abilities. What the HTTP layer holds instead of the
 * key's row.
 */
final readonly class ApiCaller
{
    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function __construct(
        public string $keyId,
        public string $keyName,
        public string $personId,
        public array $abilities,
    ) {}

    /**
     * The caller a key is.
     */
    public static function of(ApiKey $key): self
    {
        return new self($key->id, $key->name, (string) $key->user_id, $key->abilities());
    }

    /**
     * Whether the key was given this ability.
     */
    public function may(ApiAbility $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    /**
     * What a revision this key writes records as its `via`.
     */
    public function via(): string
    {
        return 'api:'.$this->keyName;
    }

    /**
     * The person the key acts as, or null when they are gone.
     */
    public function person(): ?User
    {
        return User::query()->find($this->personId);
    }
}
