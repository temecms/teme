<?php

/**
 * Tools › Redirects (I-871, I-872): the redirects a page at a time, found
 * by address or note and filtered, and the 404 log on its Not found tab,
 * the most asked for first. It reads only; every change goes through
 * RedirectController.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Redirects\Queries\NotFoundEntries;
use App\Domain\Redirects\Queries\RedirectList;
use App\Domain\Redirects\RedirectSource;
use App\Domain\Redirects\RedirectStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

final class RedirectScreenController extends Controller
{
    /** Where an import's report waits in the session, for one visit. */
    public const string IMPORTED = 'redirects.imported';

    public function index(Request $request, RedirectList $list, NotFoundEntries $log, SettingsReader $settings): Response
    {
        if ($request->query('tab') === 'not-found') {
            return $this->notFoundTab($request, $list, $log, $settings);
        }

        $search = mb_substr(trim($request->string('search')->toString()), 0, RedirectList::LONGEST_SEARCH);
        $from = mb_substr(trim($request->string('from')->toString()), 0, RedirectSource::LONGEST);
        $filters = self::filters($request);
        $chosen = [
            'on' => match ($filters['on']) {
                true => 'on',
                false => 'off',
                null => null,
            },
            'status' => $filters['status'],
            'origin' => $filters['origin'],
        ];

        return Inertia::render('tools/redirects', [
            ...$list->page($search, self::perPage($request), self::cursor($request, 'after'), self::cursor($request, 'before'), $filters),
            'search' => $search,
            'filters' => $chosen,
            'statuses' => array_map(fn (RedirectStatus $status): array => ['value' => $status->value, 'label' => $status->label()], RedirectStatus::cases()),
            'shown' => $list->count($search, $filters),
            'counts' => $list->counts(),
            'siteTimezone' => $settings->string('general', 'timezone'),
            'canManageAddresses' => $request->user()?->can('settings.addresses.manage') === true,
            // The trash's toast and the 404 log open the form with an address (I-856).
            'prefill' => $from === '' ? null : ['from' => $from],
            'imported' => $request->session()->get(self::IMPORTED),
        ]);
    }

    /**
     * The 404 log's old address, which leads to its tab on Tools › Redirects
     * with the page it named.
     */
    public function notFound(Request $request): RedirectResponse
    {
        $query = ['tab' => 'not-found', ...array_filter($request->only(['count', 'path']), is_string(...))];

        return redirect()->route('tools.redirects.index', $query);
    }

    /**
     * The Not found tab: the 404 log, the most asked for first, a page at a time.
     */
    private function notFoundTab(Request $request, RedirectList $list, NotFoundEntries $log, SettingsReader $settings): Response
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
     * The filters the address names, each null when it names none or one
     * the list doesn't know: switched on or off, a status, and an origin.
     *
     * @return array{on: bool|null, status: int|null, origin: string|null}
     */
    private static function filters(Request $request): array
    {
        $origin = $request->string('origin')->toString();

        return [
            'on' => match ($request->string('state')->toString()) {
                'on' => true,
                'off' => false,
                default => null,
            },
            'status' => RedirectStatus::tryFrom($request->integer('status'))?->value,
            'origin' => in_array($origin, ['manual', 'import', 'plugin'], true) ? $origin : null,
        ];
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
