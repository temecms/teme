<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Redirects\Operations\CreateRedirect;
use App\Domain\Redirects\Operations\DeleteRedirects;
use App\Domain\Redirects\Operations\EditRedirect;
use App\Domain\Redirects\Operations\Input\CreateRedirectInput;
use App\Domain\Redirects\Operations\Input\DeleteRedirectsInput;
use App\Domain\Redirects\Operations\Input\EditRedirectInput;
use App\Domain\Redirects\Operations\Input\SwitchRedirectsInput;
use App\Domain\Redirects\Operations\SwitchRedirects;
use App\Domain\Redirects\Queries\RedirectList;
use App\Domain\Redirects\RedirectState;
use App\Domain\Redirects\RedirectTarget;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Tools › Redirects' changes (I-694, I-871, I-872): each handed to its
 * Operation, which authorizes it again and refuses what it must. This says
 * what happened in a toast, and goes back to the list it came from. It
 * changes nothing itself.
 *
 * @phpstan-import-type RedirectRow from RedirectList
 * @phpstan-import-type RedirectTo from RedirectList
 */
final class RedirectController extends Controller
{
    public function store(Request $request, CreateRedirect $create, RedirectList $list): RedirectResponse
    {
        /** @var array{id: string, state: RedirectState, label: string} $result */
        $result = $create->execute(new CreateRedirectInput(...[...self::asked($request), 'actorId' => self::actor($request)]));

        return self::told(self::sentence(__('Redirect added.'), $result, $list->find($result['id'])));
    }

    public function update(Request $request, EditRedirect $edit, RedirectList $list, string $redirect): RedirectResponse
    {
        /** @var array{id: string, state: RedirectState, label: string} $result */
        $result = $edit->execute(new EditRedirectInput(...['redirectId' => $redirect, ...self::asked($request), 'actorId' => self::actor($request)]));

        return self::told(self::sentence(__('Redirect saved.'), $result, $list->find($result['id'])));
    }

    public function toggle(Request $request, SwitchRedirects $switch, RedirectList $list): RedirectResponse
    {
        $ids = self::ids($request);
        $on = $request->boolean('on');
        // Read before the switch: Undo switches back these, and only these.
        $changing = $list->changing($ids, $on);
        $changed = $switch->execute(new SwitchRedirectsInput($ids, $on, self::actor($request)));

        if ($request->boolean('undo')) {
            return self::told(__('Undone.'));
        }

        if ($changed === 0) {
            return self::told($on ? __('Nothing changed: they were already on.') : __('Nothing changed: they were already off.'));
        }

        $undo = ['href' => route('tools.redirects.toggle', [], false), 'data' => ['ids' => $changing, 'on' => ! $on, 'undo' => true]];
        $one = count($changing) === 1 ? $list->find($changing[0]) : null;

        return self::told(match (true) {
            $one !== null && ! $on => __('Redirect switched off. :from sends nobody on until it’s on again.', ['from' => $one['from']]),
            $one !== null && $one['state'] !== RedirectState::On->value => __('Redirect switched on. :state.', ['state' => $one['stateLabel']]),
            $one !== null => __('Redirect switched on. :from sends readers on again.', ['from' => $one['from']]),
            $on => __(':count redirects switched on.', ['count' => $changed]),
            default => __(':count redirects switched off. They send nobody on until they’re on again.', ['count' => $changed]),
        }, $undo);
    }

    public function destroy(Request $request, DeleteRedirects $delete, RedirectList $list): RedirectResponse
    {
        $ids = self::ids($request);
        // Read before it goes, to name it.
        $only = count($ids) === 1 ? $list->find($ids[0]) : null;
        $deleted = $delete->execute(new DeleteRedirectsInput($ids, self::actor($request)));

        return self::told(match (true) {
            $deleted === 0 => __('Nothing deleted: they were already gone.'),
            $only !== null => __('Redirect deleted. :from no longer sends readers on.', ['from' => $only['from']]),
            default => trans_choice('1 redirect deleted. Its address no longer sends readers on.|:count redirects deleted. Their addresses no longer send readers on.', $deleted),
        });
    }

    public function gone(Request $request, CreateRedirect $create, RedirectList $list): RedirectResponse
    {
        $from = $request->string('from')->toString();

        try {
            /** @var array{id: string, state: RedirectState, label: string} $result */
            $result = $create->execute(new CreateRedirectInput($from, 410, actorId: self::actor($request)));
        } catch (ValidationException $refused) {
            // One click from a row, with no form to hold the refusal (I-871).
            return self::told((string) collect($refused->errors())->flatten()->first(), type: 'error');
        }

        $row = $list->find($result['id']);

        return self::told($result['state'] === RedirectState::On
            ? __('Marked as gone. :from now answers 410 (gone).', ['from' => $row['from'] ?? $from])
            : __('Marked as gone. :state.', ['state' => $result['label']]));
    }

    /**
     * What the form asks for, in CreateRedirectInput's names: only the
     * target the chosen kind names; a kind chosen with nothing in it is an
     * empty ID, which the Operation refuses under its own field.
     *
     * @return array{from: string, status: int, to: string, contentId: string|null, mediaId: string|null, note: string}
     */
    private static function asked(Request $request): array
    {
        $kind = $request->string('kind')->toString();

        return [
            'from' => $request->string('from')->toString(),
            'status' => $request->integer('status'),
            'to' => $kind === 'address' ? $request->string('to')->toString() : '',
            'contentId' => $kind === 'item' ? $request->string('item')->toString() : null,
            'mediaId' => $kind === 'file' ? $request->string('file')->toString() : null,
            'note' => $request->string('note')->toString(),
        ];
    }

    /**
     * What a saved redirect does now, after $done: where it sends readers,
     * what it answers, or what it waits for (I-858).
     *
     * @param  array{id: string, state: RedirectState, label: string}  $result
     * @param  RedirectRow|null  $row
     */
    private static function sentence(string $done, array $result, ?array $row): string
    {
        if ($row === null) {
            return $done;
        }

        return $done.' '.match (true) {
            $result['state'] === RedirectState::Off => __('It’s switched off, so it sends nobody on.'),
            $result['state'] !== RedirectState::On => $result['label'].'.',
            $row['to']['kind'] === null => __(':from now answers :status (:name).', ['from' => $row['from'], 'status' => $row['status'], 'name' => mb_strtolower($row['statusLabel'])]),
            default => __(':from now sends readers to :to.', ['from' => $row['from'], 'to' => self::where($row['to'])]),
        };
    }

    /**
     * Where a redirect sends readers, as a toast names it: an item by its
     * title, a file by its name, else the address.
     *
     * @param  RedirectTo  $to
     */
    private static function where(array $to): string
    {
        return match ($to['kind']) {
            RedirectTarget::CONTENT => __('“:title”', ['title' => $to['title'] ?? '']),
            RedirectTarget::MEDIA => __('the file :name', ['name' => $to['title'] ?? '']),
            default => (string) $to['address'],
        };
    }

    /**
     * The toast, then back where the change came from.
     *
     * @param  array{href: string, data: array<string, mixed>}|null  $undo
     */
    private static function told(string $message, ?array $undo = null, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message, ...($undo === null ? [] : ['undo' => $undo])]);

        return self::back();
    }

    /**
     * Back to the screen the change came from, its search and page kept,
     * without the `from` that opened the form, so the form opens once
     * (I-856). Only a path under Tools › Redirects is taken from the
     * previous address, never its host.
     */
    private static function back(): RedirectResponse
    {
        $list = route('tools.redirects.index', [], false);
        $previous = parse_url(url()->previous($list));
        $path = is_array($previous) && isset($previous['path']) && str_starts_with($previous['path'], $list) ? $previous['path'] : $list;
        parse_str(is_array($previous) ? ($previous['query'] ?? '') : '', $query);
        unset($query['from']);

        return redirect()->to($path.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * The redirects a request names, as given.
     *
     * @return list<string>
     */
    private static function ids(Request $request): array
    {
        return array_values(array_filter((array) $request->input('ids', []), is_string(...)));
    }
}
