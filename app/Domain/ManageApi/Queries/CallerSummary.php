<?php

/**
 * GET /api/manage/v1/me's answer: the key, the person it acts as, and the
 * site's name and address, generated from the current site address.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi\Queries;

use App\Domain\ManageApi\ApiAbility;
use App\Domain\ManageApi\ApiCaller;
use App\Models\User;
use Teme\Contracts\Settings\SettingsReader;

final readonly class CallerSummary
{
    public function __construct(private SettingsReader $settings) {}

    /**
     * Returns the key's ID, name and abilities, its person's ID and name,
     * and the site's name and address.
     *
     * @return array{key: array{id: string, name: string, abilities: list<string>}, person: array{id: int, name: string}, site: array{name: string, url: string}}
     */
    public function of(ApiCaller $caller): array
    {
        $person = User::query()->find($caller->personId);

        return [
            'key' => [
                'id' => $caller->keyId,
                'name' => $caller->keyName,
                'abilities' => array_map(fn (ApiAbility $ability): string => $ability->value, $caller->abilities),
            ],
            'person' => ['id' => (int) $caller->personId, 'name' => (string) $person?->name],
            'site' => [
                'name' => $this->settings->string('general', 'site_title'),
                'url' => rtrim(url('/'), '/').'/',
            ],
        ];
    }
}
