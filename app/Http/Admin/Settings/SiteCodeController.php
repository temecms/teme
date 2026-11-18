<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Site\Insertions\Operations\ApprovePluginScript;
use App\Domain\Site\Insertions\Operations\Input\ApprovePluginScriptInput;
use App\Domain\Site\Insertions\Operations\Input\RefusePluginScriptInput;
use App\Domain\Site\Insertions\Operations\Input\RevokePluginScriptInput;
use App\Domain\Site\Insertions\Operations\RefusePluginScript;
use App\Domain\Site\Insertions\Operations\RevokePluginScript;
use App\Domain\Site\Insertions\Queries\PluginScriptsList;
use App\Domain\SiteCode\Operations\DeleteSiteCode;
use App\Domain\SiteCode\Operations\Input\DeleteSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\UpdateAdsTxtInput;
use App\Domain\SiteCode\Operations\SaveSiteCode;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Domain\SiteCode\Operations\UpdateAdsTxt;
use App\Domain\SiteCode\Queries\SiteCodeScreen;
use App\Domain\SiteCode\SiteCode;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Site code (I-863): the entries and their changes, each change
 * handed to its Operation, which asks for the password again; ads.txt through
 * UpdateAdsTxt; and plugins' scripts approved, refused or revoked through
 * their Operations. It changes nothing itself.
 */
final class SiteCodeController extends Controller
{
    public function show(SiteCodeScreen $screen, PluginScriptsList $scripts): Response
    {
        return Inertia::render('settings/site-code', [...$screen->data(), 'pluginScripts' => $scripts->all()]);
    }

    public function store(Request $request, SaveSiteCode $save): RedirectResponse
    {
        return $this->save($request, $save, null);
    }

    public function update(Request $request, SaveSiteCode $save, string $entry): RedirectResponse
    {
        return $this->save($request, $save, $entry);
    }

    public function toggle(Request $request, SwitchSiteCode $switch, string $entry): RedirectResponse
    {
        $on = $request->boolean('on');

        /** @var array{name: string, changed: bool} $result */
        $result = $switch->execute(new SwitchSiteCodeInput($entry, $on, (string) $request->user()?->getAuthIdentifier()));

        return self::told(match (true) {
            ! $result['changed'] => __('Nothing changed.'),
            $on => __('Switched on. Every public page includes “:name” again.', ['name' => $result['name']]),
            default => __('Switched off. No page includes “:name” until it’s on again.', ['name' => $result['name']]),
        });
    }

    public function destroy(Request $request, DeleteSiteCode $delete, string $entry): RedirectResponse
    {
        /** @var array{name: string, changed: bool} $result */
        $result = $delete->execute(new DeleteSiteCodeInput($entry, (string) $request->user()?->getAuthIdentifier()));

        return self::told(__('Code deleted. No page includes “:name” any more.', ['name' => $result['name']]));
    }

    public function updateAdsTxt(Request $request, UpdateAdsTxt $update): RedirectResponse
    {
        try {
            $update->execute(new UpdateAdsTxtInput(
                adsTxt: (string) $request->input('ads_txt', ''),
                expectedVersion: $request->integer('expected_version'),
                actorId: (string) $request->user()?->getAuthIdentifier(),
                mode: (string) $request->input('ads_txt_mode', 'text'),
                redirectUrl: (string) $request->input('ads_txt_redirect', ''),
            ));
        } catch (StaleSettingsException) {
            throw ValidationException::withMessages([
                'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
            ]);
        }

        return self::told(__('ads.txt saved.'));
    }

    public function approveScript(Request $request, ApprovePluginScript $approve, string $script): RedirectResponse
    {
        return self::decided(
            fn (): array => $approve->execute(new ApprovePluginScriptInput($script, (string) $request->user()?->getAuthIdentifier())),
            'Script approved. Pages that hold “:name” run it now.',
        );
    }

    public function refuseScript(Request $request, RefusePluginScript $refuse, string $script): RedirectResponse
    {
        return self::decided(
            fn (): array => $refuse->execute(new RefusePluginScriptInput($script, (string) $request->user()?->getAuthIdentifier())),
            'Script refused. Pages leave it out of “:name”.',
        );
    }

    public function revokeScript(Request $request, RevokePluginScript $revoke, string $script): RedirectResponse
    {
        return self::decided(
            fn (): array => $revoke->execute(new RevokePluginScriptInput($script, (string) $request->user()?->getAuthIdentifier())),
            'Decision revoked. Pages leave the script out of “:name” until it’s approved.',
        );
    }

    private function save(Request $request, SaveSiteCode $save, ?string $entry): RedirectResponse
    {
        $name = trim((string) $request->input('name'));
        $placement = (string) $request->input('placement');
        $on = $request->boolean('on');

        // The field's lines, one host a line; the Operation trims, checks and drops the empty ones.
        $origins = preg_split('/\R/u', (string) $request->input('origins', '')) ?: [];

        /** @var array{id: string, added: bool, changed: bool} $result */
        $result = $save->execute(new SaveSiteCodeInput($entry, $name, $placement, (string) $request->input('code'), $on, (string) $request->user()?->getAuthIdentifier(), $origins));

        if (! $result['changed']) {
            return self::told(__('Nothing changed.'));
        }

        // The Operation refused an unknown placement, so it is one of PLACES.
        $where = match (true) {
            ! $on => __('“:name” is off, so no page includes it yet.', ['name' => $name]),
            $placement === SiteCode::PLACED => __('“:name” is ready to place with the Custom HTML card in a theme region.', ['name' => $name]),
            default => __('Every public page includes “:name” :at.', ['name' => $name, 'at' => SiteCode::PLACES[$placement]['at']]),
        };

        return self::told(($result['added'] ? __('Code added.') : __('Code saved.')).' '.$where);
    }

    /**
     * A decision on a plugin's script, said in a toast; one refused because
     * someone else decided first is said as an error.
     *
     * @param  Closure(): array{name: string, plugin: string}  $decide
     */
    private static function decided(Closure $decide, string $message): RedirectResponse
    {
        try {
            $result = $decide();
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('settings.site-code');
        }

        return self::told(__($message, ['name' => $result['name']]));
    }

    private static function told(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('settings.site-code');
    }
}
