<?php

declare(strict_types=1);

namespace App\Domain\ManageApi\Queries;

use App\Domain\ManageApi\ApiAbility;
use App\Domain\ManageApi\ManageApiSettings;
use App\Domain\ManageApi\Models\ApiKey;
use App\Domain\Settings\Queries\SettingsForm;
use App\Models\User;
use RuntimeException;

/**
 * Settings › Management API's page: the switch and the limit as the
 * settings form draws them, every key with its person and when it was
 * made, last used, expires or was revoked, and the choices a new key takes.
 * A key's secret is never read: only its start, shown in the list.
 */
final readonly class ManagementApiScreen
{
    public function __construct(private SettingsForm $form) {}

    /**
     * Returns the page's props: the group's description, its switch and
     * limit fields with their values and version, the keys, the people a key
     * may act as, the abilities, and the API's address.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $group = $this->form->find(ManageApiSettings::KEY) ?? throw new RuntimeException('No settings group [management_api] is registered.');
        $fields = array_column($group['fields'], null, 'key');

        $keys = ApiKey::query()
            ->orderByRaw('revoked_at IS NOT NULL')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
        $people = User::query()->whereIn('id', $keys->pluck('user_id')->unique()->all())->pluck('name', 'id');

        return [
            'description' => $group['group']['description'],
            'switch' => $fields[ManageApiSettings::ENABLED],
            'limit' => $fields[ManageApiSettings::PER_MINUTE],
            'values' => $group['values'],
            'version' => $group['version'],
            'endpoint' => rtrim(url('/api/manage/v1'), '/'),
            'keys' => $keys->map(fn (ApiKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'start' => $key->token_start,
                'person' => (string) ($people[$key->user_id] ?? ''),
                'abilities' => $key->abilities,
                'createdAt' => $key->created_at?->toIso8601String(),
                'lastUsedAt' => $key->last_used_at?->toIso8601String(),
                'expiresAt' => $key->expires_at?->toIso8601String(),
                'revokedAt' => $key->revoked_at?->toIso8601String(),
                'live' => $key->isLive(),
            ])->values()->all(),
            'people' => User::query()
                ->whereNull('suspended_at')
                ->orderBy('name')
                ->orderBy('id')
                ->limit(500)
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => (string) $user->id, 'label' => (string) $user->name])
                ->values()
                ->all(),
            'abilities' => array_map(fn (ApiAbility $ability): array => ['value' => $ability->value, 'label' => $ability->label()], ApiAbility::cases()),
        ];
    }
}
