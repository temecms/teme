<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Redirects\Queries\NotFoundEntries;
use App\Domain\Redirects\Queries\RedirectList;
use App\Domain\Redirects\RedirectSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Tools › Redirects (I-871, I-872): the redirects a page at a time, found
 * by address or note, and the 404 log beside them, the most asked for
 * first. It reads only; every change goes through RedirectController.
 */
final class RedirectScreenController extends Controller
{
    /** Where an import's report waits in the session, for one visit. */
    public const string IMPORTED = 'redirects.imported';

    public function index(Request $request, RedirectList $list, SettingsReader $settings): Response
    {
        $search = mb_substr(trim($request->string('search')->toString()), 0, RedirectList::LONGEST_SEARCH);
        $from = mb_substr(trim($request->string('from')->toString()), 0, RedirectSource::LONGEST);

        return Inertia::render('tools/redirects', [
            ...$list->page($search, self::perPage($request), self::cursor($request, 'after'), self::cursor($request, 'before')),
            'search' => $search,
            'counts' => $list->counts(),
            'siteTimezone' => $settings->string('general', 'timezone'),
            'canManageAddresses' => $request->user()?->can('settings.addresses.manage') === true,
            // The trash's toast and the 404 log open the form with an address (I-856).
            'prefill' => $from === '' ? null : ['from' => $from],
            'imported' => $request->session()->get(self::IMPORTED),
        ]);
    }

    public function notFound(Request $request, RedirectList $list, NotFoundEntries $log, SettingsReader $settings): Response
    {
        $count = $request->string('count')->toString();
        $path = $request->string('path')->toString();
        $after = preg_match('/^\d{1,18}$/', $count) === 1 && $path !== '' && mb_strlen($path) <= RedirectSource::LONGEST
            ? ['count' => (int) $count, 'path' => $path]
            : null;
        $page = $log->mostAsked(self::perPage($request), $after);

        return Inertia::render('tools/not-found', [
            'rows' => $page['rows'],
            'next' => $page['next'],
            'paged' => $after !== null,
            'keep' => NotFoundLogSection::size(),
            'counts' => $list->counts(),
            'siteTimezone' => $settings->string('general', 'timezone'),
            // Where the log's size and what it ignores are changed (I-874).
            'settingsUrl' => $request->user()?->can('settings.seo.manage') === true
                ? route('settings.seo').'#section-not-found-log'
                : null,
        ]);
    }

    /**
     * The person's rows a page (lists.per_page).
     */
    private static function perPage(Request $request): int
    {
        return (int) PreferencesFor::user($request->user())['lists.per_page'];
    }

    /**
     * A redirect's ID from the address, or null when it isn't one: a
     * lower-case ULID (I-418).
     */
    private static function cursor(Request $request, string $key): ?string
    {
        $id = $request->string($key)->toString();

        return preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1 ? $id : null;
    }
}
