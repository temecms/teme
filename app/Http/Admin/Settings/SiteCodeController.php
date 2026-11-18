<?php

/**
 * Settings › Site code (I-863): the entries and their changes, each change
 * handed to its Operation, which asks for the password again; ads.txt through
 * UpdateAdsTxt. It changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Settings\Exceptions\StaleSettingsException;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SiteCodeController extends Controller
{
    public function show(SiteCodeScreen $screen): Response
    {
        return Inertia::render('settings/site-code', $screen->data());
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

    /**
     * @since 1.0.0
     */
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

    private static function told(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('settings.site-code');
    }
}
