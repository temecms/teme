<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Inbox\Operations\Input\MarkInboxReadInput;
use App\Domain\Inbox\Operations\MarkInboxRead;
use App\Domain\Inbox\Queries\InboxFor;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The inbox (I-221): the signed-in person's own background results, a page at
 * a time. Opening one marks it read through MarkInboxRead and follows its
 * link; Mark all as read marks the rest. The bell's latest items come as
 * JSON. It shows nobody else's.
 */
final class InboxController extends Controller
{
    /** How many items the bell's panel lists. */
    public const int LATEST = 10;

    public function index(Request $request, InboxFor $inbox, SettingsReader $settings): Response
    {
        $person = $request->user();
        abort_if($person === null, 403);

        $found = $inbox->page($person, self::optional($request, 'after'), self::optional($request, 'before'), (int) PreferencesFor::user($person)['lists.per_page']);

        return Inertia::render('inbox/index', [
            'rows' => $found['rows'],
            'newer' => $found['newer'] === null ? null : route('inbox.index', ['before' => $found['newer']], false),
            'older' => $found['older'] === null ? null : route('inbox.index', ['after' => $found['older']], false),
            'unread' => $inbox->unread($person),
            'siteTimezone' => $settings->string('general', 'timezone'),
        ]);
    }

    public function latest(Request $request, InboxFor $inbox, SettingsReader $settings): JsonResponse
    {
        $person = $request->user();
        abort_if($person === null, 403);

        return response()->json([
            'rows' => $inbox->page($person, null, null, self::LATEST)['rows'],
            'unread' => $inbox->unread($person),
            'siteTimezone' => $settings->string('general', 'timezone'),
            'inboxHref' => route('inbox.index', [], false),
        ]);
    }

    public function open(Request $request, string $item, InboxFor $inbox, MarkInboxRead $mark): RedirectResponse
    {
        $person = $request->user();
        abort_if($person === null, 403);

        // Someone else's item answers as one that doesn't exist.
        $found = $inbox->find($person, $item);
        abort_if($found === null, 404);

        $mark->execute(new MarkInboxReadInput((string) $person->getAuthIdentifier(), $item));

        return redirect($found['href'] ?? route('inbox.index', [], false));
    }

    public function readAll(Request $request, MarkInboxRead $mark): RedirectResponse
    {
        $mark->execute(new MarkInboxReadInput((string) $request->user()?->getAuthIdentifier()));

        return redirect()->back(fallback: route('inbox.index'));
    }

    /**
     * A cursor from the address, or none.
     */
    private static function optional(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value === '' ? null : $value;
    }
}
