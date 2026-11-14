<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\SiteCode\Operations\DeleteSiteCode;
use App\Domain\SiteCode\Operations\Input\DeleteSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\SaveSiteCode;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Domain\SiteCode\Queries\SiteCodeScreen;
use App\Domain\SiteCode\SiteCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Site code (I-863): the entries and their changes, each change
 * handed to its Operation, which asks for the password again. It changes
 * nothing itself.
 */
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
        $where = $on
            ? __('Every public page includes “:name” :at.', ['name' => $name, 'at' => SiteCode::PLACES[$placement]['at']])
            : __('“:name” is off, so no page includes it yet.', ['name' => $name]);

        return self::told(($result['added'] ? __('Code added.') : __('Code saved.')).' '.$where);
    }

    private static function told(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('settings.site-code');
    }
}
