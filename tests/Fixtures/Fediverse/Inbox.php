<?php

declare(strict_types=1);

namespace Tests\Fixtures\Fediverse;

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Signatures\Signer;
use App\Domain\Settings\Modules;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Testing\TestResponse;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * Activities posted to the site's inboxes as a remote server sends them,
 * signed with the remote key pair, and the fediverse's switch, which the
 * settings page will register: until then a test registers it here.
 */
final class Inbox
{
    public const string ALICE = 'https://remote.example/users/alice';

    /**
     * Posts the activity, signed by $from with the remote key pair.
     *
     * @param  array<string, mixed>  $activity
     * @param  array<string, string>  $headers  replacing what the signer wrote
     */
    public static function post(string $path, array $activity, string $from = self::ALICE, array $headers = [], string $address = '203.0.113.9', ?string $body = null): TestResponse
    {
        $body ??= json_encode($activity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signer = new FediverseActor(['uri' => $from, 'private_key' => Actors::keys('remote')['private']]);
        $signed = app(Signer::class)->sign('POST', Requests::url($path), [], $body, $signer);

        return Requests::post($path, $body, [...$signed, ...$headers], $address);
    }

    /**
     * A Follow of the site's actor from $from.
     *
     * @return array<string, mixed>
     */
    public static function follow(string $object, string $from = self::ALICE, string $id = self::ALICE.'#follows/1'): array
    {
        return ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $id, 'type' => 'Follow', 'actor' => $from, 'object' => $object];
    }

    /**
     * Switches the fediverse on or off, registering its switch as the settings page will.
     */
    public static function switch(bool $on): void
    {
        $group = Modules::group();
        $options = $on ? [Modules::ON => 'On', Modules::OFF => 'Off'] : [Modules::OFF => 'Off', Modules::ON => 'On'];

        app(SettingsRegistry::class)->replace(new SettingsGroup(
            key: $group->key,
            label: $group->label,
            description: $group->description,
            section: $group->section,
            ability: $group->ability,
            settings: [...$group->settings, Setting::select('fediverse', 'Fediverse', 'Test switch.', '0.1.0', $options)],
            since: $group->since,
            screen: $group->screen,
            cacheTags: $group->cacheTags,
        ));
    }
}
