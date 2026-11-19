<?php

/**
 * A plugin's door for sending to the site's followers, given with
 * fediverse.send: it holds only the plugin's name, and SendPluginActivity
 * stores what it sends for the person the plugin acts for; core signs and
 * delivers it after commit.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Sockets\Operations\Input\SendPluginActivityInput;
use App\Domain\Fediverse\Sockets\Operations\SendPluginActivity;
use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Fediverse\FediverseSender;
use Teme\Contracts\Fediverse\SendRefused;

final readonly class PluginSender implements FediverseSender
{
    public function __construct(private string $plugin) {}

    public function send(string $type, array|string $object, ?string $actorUri = null): string
    {
        try {
            /** @var Activity $activity */
            $activity = app(SendPluginActivity::class)->execute(new SendPluginActivityInput($this->plugin, $type, $object, $actorUri, $this->person()));

            return (string) $activity->payload['id'];
        } catch (ValidationException $refused) {
            throw new SendRefused((string) collect($refused->errors())->flatten()->first());
        } catch (AuthorizationException $refused) {
            throw new SendRefused($refused->getMessage());
        }
    }

    /**
     * Who the plugin acts for: the signed-in person; for work the server
     * runs (a queued job, a schedule, a command), the administrator who
     * enabled the plugin; for a visitor who is not signed in, nobody.
     */
    private function person(): ?string
    {
        $id = Auth::id();

        if ($id !== null) {
            return (string) $id;
        }

        if (! app()->runningInConsole()) {
            return null;
        }

        $enabledBy = PluginRecord::query()->where('name', $this->plugin)->value('consented_by');

        return $enabledBy === null ? null : (string) $enabledBy;
    }
}
