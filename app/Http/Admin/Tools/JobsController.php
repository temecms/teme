<?php

/**
 * Tools › Jobs (I-104): every tracked job, a keyset page at a time, polled
 * while one is under way; retrying and cancelling through RetryJob and
 * CancelJob. It changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

final class JobsController extends Controller
{
    public function index(Request $request, TrackedJobList $list, SettingsReader $settings): Response
    {
        $found = null;

        // One read serves the rows and both pages either side, and none when a poll asks for the failed count alone.
        $read = function () use (&$found, $list, $request): array {
            return $found ??= $list->page(self::optional($request, 'after'), self::optional($request, 'before'), (int) PreferencesFor::user($request->user())['lists.per_page']);
        };

        return Inertia::render('tools/jobs', [
            'rows' => fn (): array => $read()['rows'],
            'newer' => fn (): ?string => self::pageAt('before', $read()['newer']),
            'older' => fn (): ?string => self::pageAt('after', $read()['older']),
            'failed' => fn (): int => $list->failedCount(),
            'siteTimezone' => $settings->string('general', 'timezone'),
            'inboxHref' => route('inbox.index', [], false),
        ]);
    }

    public function retry(Request $request, string $job, RetryJob $retry): RedirectResponse
    {
        try {
            $record = $retry->execute(new RetryJobInput($job, self::actor($request)));
        } catch (ValidationException $refused) {
            return self::told($refused->validator->errors()->first(), 'error');
        }

        return self::told(__('Retrying :label. It carries on from where it stopped.', ['label' => $record->label]));
    }

    public function cancel(Request $request, string $job, CancelJob $cancel): RedirectResponse
    {
        try {
            $record = $cancel->execute(new CancelJobInput($job, self::actor($request)));
        } catch (ValidationException $refused) {
            return self::told($refused->validator->errors()->first(), 'error');
        }

        return self::told(__('Cancelled :label. What it did stays done.', ['label' => $record->label]));
    }

    public function retryFailed(Request $request, TrackedJobList $list, RetryJob $retry): RedirectResponse
    {
        $retried = 0;
        $refused = 0;

        // One Operation a job, each authorized on its own (I-143); at most 100 a press.
        foreach ($list->failedIds(100) as $id) {
            try {
                $retry->execute(new RetryJobInput($id, self::actor($request)));
                $retried++;
            } catch (AuthorizationException|ValidationException|ModelNotFoundException) {
                $refused++;
            }
        }

        if ($retried === 0) {
            return $refused === 0
                ? self::told(__('No failed jobs to retry.'), 'info')
                : self::told(__('No failed job could be retried.'), 'error');
        }

        $told = trans_choice('Retrying :count failed job.|Retrying :count failed jobs.', $retried);

        return self::told($refused === 0 ? $told : $told.' '.__(':count couldn’t be retried.', ['count' => $refused]));
    }

    /**
     * Who is asking, as an Operation's input carries them.
     */
    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * Back to the page the person was on, with a toast.
     */
    private static function told(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->back(fallback: route('tools.jobs.index'));
    }

    /**
     * The address of the page a cursor opens, or null for none.
     */
    private static function pageAt(string $way, ?string $cursor): ?string
    {
        return $cursor === null ? null : route('tools.jobs.index', [$way => $cursor], false);
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
