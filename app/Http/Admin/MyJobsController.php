<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Inbox\Queries\InboxFor;
use App\Domain\Jobs\JobAccess;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The header's jobs button (I-104): the signed-in person's own latest jobs and
 * counts, as JSON, and their own Retry and Cancel through RetryJob and
 * CancelJob, which refuse anyone else's. It changes nothing itself.
 */
final class MyJobsController extends Controller
{
    /** How many of a person's jobs the panel lists. */
    public const int LATEST = 10;

    public function index(Request $request, TrackedJobList $jobs, InboxFor $inbox, SettingsReader $settings): JsonResponse
    {
        $person = $request->user();
        abort_if($person === null, 403);

        $id = (int) $person->getAuthIdentifier();

        return response()->json([
            'rows' => $jobs->startedBy($id, self::LATEST),
            ...$jobs->countsFor($id),
            'unread' => $inbox->unread($person),
            'siteTimezone' => $settings->string('general', 'timezone'),
            'toolsJobsHref' => $person->can(JobAccess::MANAGE) ? route('tools.jobs.index', [], false) : null,
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

        return redirect()->back(fallback: route('dashboard'));
    }
}
