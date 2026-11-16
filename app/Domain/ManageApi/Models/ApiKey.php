<?php

declare(strict_types=1);

namespace App\Domain\ManageApi\Models;

use App\Domain\ManageApi\ApiAbility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One management API key (docs/proposals/management-api.md): its name, the
 * person it acts as, its abilities, the hash of its secret and the start
 * shown in the list. Only CreateApiKey and RevokeApiKey write it, and
 * ApiKeys touches when it was last used.
 *
 * @property string $id
 * @property string $name
 * @property int $user_id
 * @property list<string> $abilities
 * @property string $token_hash
 * @property string $token_start
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
final class ApiKey extends Model
{
    use HasUlids;

    protected $table = 'api_keys';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The key's abilities, those the list still knows.
     *
     * @return list<ApiAbility>
     */
    public function abilities(): array
    {
        return array_values(array_filter(array_map(
            fn (string $value): ?ApiAbility => ApiAbility::tryFrom($value),
            $this->abilities,
        )));
    }

    /**
     * Whether the key was given this ability.
     */
    public function allows(ApiAbility $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /**
     * Whether the key still signs in: not revoked and not past its expiry.
     */
    public function isLive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
