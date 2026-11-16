<?php

declare(strict_types=1);

namespace App\Domain\Settings\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `UpdateSettings` needs: the group, the values to change, the keys to
 * reset, any secrets, the version the form was opened on, who is asking,
 * and, for a plugin's settings, the hosts they agree to it reaching.
 */
final readonly class UpdateSettingsInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values  non-secret settings, by key
     * @param  list<string>  $reset  keys to return to their default
     * @param  array<string, ?string>  $secrets  '' leaves a secret unchanged; null clears it
     * @param  list<string>  $agreedHosts  new hosts in a plugin's `hostsFrom` settings the person agrees to it reaching (docs/plans/plugin-sockets-1.md, A2)
     */
    public function __construct(
        public string $group,
        public array $values,
        public array $reset,
        public array $secrets,
        public int $expectedVersion,
        public ?string $actorId,
        public string $origin = 'human',
        public array $agreedHosts = [],
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
