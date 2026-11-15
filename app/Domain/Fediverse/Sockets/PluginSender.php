<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Sockets\Operations\Input\SendPluginActivityInput;
use App\Domain\Fediverse\Sockets\Operations\SendPluginActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Fediverse\FediverseSender;
use Teme\Contracts\Fediverse\SendRefused;

/**
 * A plugin's door for sending to the site's followers, given with
 * fediverse.send: it holds only the plugin's name, and SendPluginActivity
 * stores what it sends; core signs and delivers it after commit.
 */
final readonly class PluginSender implements FediverseSender
{
    public function __construct(private string $plugin) {}

    public function send(string $type, array|string $object, ?string $actorUri = null): string
    {
        try {
            /** @var Activity $activity */
            $activity = app(SendPluginActivity::class)->execute(new SendPluginActivityInput($this->plugin, $type, $object, $actorUri));

            return (string) $activity->payload['id'];
        } catch (ValidationException $refused) {
            throw new SendRefused((string) collect($refused->errors())->flatten()->first());
        } catch (AuthorizationException $refused) {
            throw new SendRefused($refused->getMessage());
        }
    }
}
