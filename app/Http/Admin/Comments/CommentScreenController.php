<?php

/**
 * The Comments screen (1.8c): the list as one person may see it, its views
 * and counts, the item it's narrowed to and the drawer its address opens, and
 * what Empty spam and Empty trash would delete (1.8c′); and, instead, the page for comments switched off or for an item or comment
 * the person doesn't look after.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Comments;

use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\Queries\CommentDetails;
use App\Domain\Comments\Queries\CommentList;
use App\Domain\Comments\Queries\CommentListRequest;
use App\Domain\Comments\Queries\EmptyingRequest;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Settings\Modules;
use App\Domain\Support\Queries\ListPages;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Teme\Contracts\Settings\SettingsReader;

final class CommentScreenController extends Controller
{
    public function index(Request $request, CommentList $list, CommentDetails $details, SettingsReader $settings, CommentStatusRegistry $statuses): Response|HttpResponse
    {
        // Study H: a switched-off module's route answers as if it weren't
        // there, with v9's page (D15).
        if (! Modules::on(Modules::COMMENTS)) {
            return Inertia::render('comments/off', ['canSwitch' => $request->user()?->can('settings.modules.manage') === true])->toResponse($request)->setStatusCode(404);
        }

        $viewer = (int) $request->user()?->getAuthIdentifier();
        $requested = $request->string('view')->toString();
        $addressed = in_array($requested, $list->views(), true) ? $requested : null;
        $commentId = $request->string('comment')->toString();
        $itemId = $request->string('item')->toString();
        $open = null;

        if ($commentId !== '') {
            // A comment's ID is 26 lower-case letters and digits (1.8a's D16).
            if (preg_match('/^[0-9a-z]{26}$/', $commentId) !== 1) {
                abort(404);
            }

            $found = $details->find($viewer, $commentId) ?? abort(404);

            if (! $found['mine']) {
                return $this->refused($request, $found['item'], $addressed);
            }

            $open = $found['details'];
        }

        $item = null;

        if ($itemId !== '') {
            $item = $list->item($viewer, $itemId) ?? abort(404);

            if (! $item['mine']) {
                return $this->refused($request, $item, $addressed);
            }
        }

        // The address's view; else, with a drawer open, its comment's own, so
        // an email's link lands on the list it's in (D13); else Pending (D4).
        $view = $addressed ?? ($open !== null && in_array($open['status'], $list->views(), true) ? $open['status'] : CommentStatus::PENDING);
        $search = $request->string('search')->toString();
        $perPage = (int) PreferencesFor::user($request->user())['lists.per_page'];
        $page = null;

        // One read serves the rows and the pages, and none when only the
        // drawer is asked for again (D13).
        $read = function () use (&$page, $list, $request, $viewer, $view, $item, $search, $perPage): array {
            return $page ??= $list->page(new CommentListRequest(
                viewerId: $viewer,
                view: $view,
                itemId: $item['id'] ?? null,
                search: $search !== '' ? $search : null,
                after: $this->optional($request, 'after'),
                before: $this->optional($request, 'before'),
                perPage: $perPage,
                page: ListPages::requested($request->query('page')),
            ));
        };

        $filters = [];

        if ($search !== '') {
            $filters['search'] = $search;
        }

        if ($item !== null) {
            $filters['item'] = $item['id'];
        }

        $everyItem = (bool) $request->user()?->can(CommentModeration::EVERY_ITEM);
        $openedAt = CarbonImmutable::now()->utc();

        return Inertia::render('comments/index', [
            'view' => $view,
            'views' => fn (): array => $list->counts($viewer, $item['id'] ?? null),
            'rows' => fn (): array => $read()['rows'],
            'paging' => fn (): array => $read()['paging'],
            'filters' => $filters,
            'item' => $item === null ? null : ['id' => $item['id'], 'title' => $item['title']],
            'own' => ! $everyItem,
            'seesAddresses' => $everyItem,
            'siteTimezone' => $settings->string('general', 'timezone'),
            'details' => $open,
            // The moment the page was drawn: Empty's cut-off (D7).
            'openedAt' => $openedAt->format('Y-m-d\TH:i:s.u\Z'),
            // What Empty spam or Empty trash would delete; a closure, so a partial reload of the drawer reads nothing more (1.8c's D13).
            'emptying' => fn (): ?array => in_array($view, $statuses->deletable(), true)
                ? $list->emptying(new EmptyingRequest($viewer, $view, $item['id'] ?? null, $search !== '' ? $search : null, $openedAt))
                : null,
            'trashDays' => $settings->int('content', 'trash_days'),
        ]);
    }

    /**
     * The page for an item, or a comment on one, the person doesn't look
     * after (C1, C7), in v9's words, answered 403 (D15).
     *
     * @param  array{title: string, singular: string, owner: string|null}  $item
     */
    private function refused(Request $request, array $item, ?string $view): HttpResponse
    {
        $message = __('You don’t look after the comments on “:title”. Editors and administrators look after every item’s comments, and authors those on their own posts.', [
            'title' => $item['title'],
        ]);

        return Inertia::render('comments/refused', [
            'message' => $message,
            'back' => [
                'label' => __('Show the comments you look after'),
                'href' => route('comments.index', $view !== null ? ['view' => $view] : [], false),
            ],
        ])->toResponse($request)->setStatusCode(403);
    }

    /**
     * A cursor from the address, or none.
     */
    private function optional(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value === '' ? null : $value;
    }
}
