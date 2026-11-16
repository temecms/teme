<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Fediverse\Operations\BlockHost;
use App\Domain\Fediverse\Operations\ChangeFediverseSettings;
use App\Domain\Fediverse\Operations\DisableAuthorActor;
use App\Domain\Fediverse\Operations\EnableAuthorActor;
use App\Domain\Fediverse\Operations\Input\AuthorActorInput;
use App\Domain\Fediverse\Operations\Input\BlockHostInput;
use App\Domain\Fediverse\Operations\Input\ChangeFediverseSettingsInput;
use App\Domain\Fediverse\Operations\Input\SwitchFediverseInput;
use App\Domain\Fediverse\Operations\Input\UnblockHostInput;
use App\Domain\Fediverse\Operations\SwitchFediverse;
use App\Domain\Fediverse\Operations\UnblockHost;
use App\Domain\Fediverse\Queries\FediverseAuthors;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use App\Domain\Fediverse\Queries\FediverseScreen;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Fediverse (docs/plans/fediverse-actors.md, T7): the switch, who
 * can be followed, the accounts, and the blocked servers, each change made
 * by its own Operation; it changes nothing itself.
 */
final class FediverseSettingsController extends Controller
{
    public function show(Request $request, FediverseScreen $screen): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('settings/fediverse', $screen->data($user));
    }

    public function update(Request $request, ChangeFediverseSettings $change): RedirectResponse
    {
        /** @var array<string, mixed> $values */
        $values = (array) $request->input('values', []);

        try {
            $change->execute(new ChangeFediverseSettingsInput($values, $request->integer('expected_version'), self::actor($request)));
        } catch (StaleSettingsException) {
            throw self::stale();
        }

        return self::done(__('Settings saved.'));
    }

    public function switchFediverse(Request $request, SwitchFediverse $switch): RedirectResponse
    {
        try {
            /** @var array{on: bool, changed: bool} $result */
            $result = $switch->execute(new SwitchFediverseInput((string) $request->input('values.fediverse'), $request->integer('expected_version'), self::actor($request)));
        } catch (StaleSettingsException) {
            throw self::stale();
        }

        return self::done(match (true) {
            ! $result['changed'] => __('Settings saved.'),
            $result['on'] => __('The fediverse is on. People on other servers can follow the site.'),
            default => __('The fediverse is paused. Followers and keys are kept for when you switch it back on.'),
        });
    }

    public function enableAuthor(Request $request, string $person, EnableAuthorActor $enable, FediverseAuthors $authors): RedirectResponse
    {
        $username = (string) $enable->execute(new AuthorActorInput($person, self::actor($request)));

        return self::done(__(':name can be followed as :handle.', [
            'name' => (string) $authors->find($person)?->name,
            'handle' => '@'.$username.'@'.FediverseDocuments::host(),
        ]));
    }

    public function disableAuthor(Request $request, string $person, DisableAuthorActor $disable, FediverseAuthors $authors): RedirectResponse
    {
        $disable->execute(new AuthorActorInput($person, self::actor($request)));

        return self::done(__(':name can no longer be followed. Their followers are kept.', ['name' => (string) $authors->find($person)?->name]));
    }

    public function block(Request $request, BlockHost $block): RedirectResponse
    {
        $host = (string) $block->execute(new BlockHostInput((string) $request->input('host'), $request->filled('reason') ? (string) $request->input('reason') : null, self::actor($request)));

        return self::done(__(':host is blocked. Nothing from it is accepted, and nothing is sent to it.', ['host' => $host]));
    }

    public function unblock(Request $request, string $host, UnblockHost $unblock): RedirectResponse
    {
        $unblock->execute(new UnblockHostInput($host, self::actor($request)));

        return self::done(__(':host is unblocked.', ['host' => $host]));
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    private static function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back(fallback: route('settings.fediverse'));
    }

    /**
     * A form opened on an older version, in the generic screen's words.
     */
    private static function stale(): ValidationException
    {
        return ValidationException::withMessages([
            'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
        ]);
    }
}
