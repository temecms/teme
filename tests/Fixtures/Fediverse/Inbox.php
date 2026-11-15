<?php

declare(strict_types=1);

namespace Tests\Fixtures\Fediverse;

use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Signatures\Signer;
use App\Domain\Settings\Modules;
use Illuminate\Testing\TestResponse;

/**
 * Activities posted to the site's inboxes as a remote server sends them,
 * signed with the remote key pair, and the fediverse's settings, stored
 * as a test needs them.
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
     * Posts the activity signed by $from with RFC 9421, as Mastodon signs.
     *
     * @param  array<string, mixed>  $activity
     */
    public static function postRfc9421(string $path, array $activity, string $from = self::ALICE, ?string $body = null): TestResponse
    {
        $body ??= json_encode($activity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $host = (string) parse_url(url('/'), PHP_URL_HOST);
        $signed = Rfc9421::sign('POST', 'https://'.$host.$path, ['host' => $host], $body, $from.'#main-key');

        return Requests::post($path, $body, $signed);
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
     * Switches the fediverse on or off, as stored settings, with no Operation.
     */
    public static function switch(bool $on): void
    {
        cmSettings([FediverseSwitch::MODULE => $on ? Modules::ON : Modules::OFF], 'modules');
    }

    /**
     * Sets who can be followed, as a stored setting, with no Operation.
     */
    public static function followable(string $who): void
    {
        cmSettings([FediverseSettingsGroup::FOLLOWABLE => $who], FediverseSettingsGroup::KEY);
    }
}
