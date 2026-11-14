<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Operations\ApproveReview;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Domain\Contents\Operations\Input\SubmitForReviewInput;
use App\Domain\Contents\Operations\ReturnForChanges;
use App\Domain\Contents\Operations\SubmitForReview;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Review (decision 5A): submitting a revision for review through
 * SubmitForReview, and deciding on it through ApproveReview or
 * ReturnForChanges. The editor asks for JSON and gets the article's new
 * state; a list's row goes back with a message.
 */
final class ReviewController extends Controller
{
    public function store(Request $request, string $content, SubmitForReview $submit): Response
    {
        $valid = $request->validate(['revision_id' => ['required', 'string', 'size:26']]);

        $submit->execute(new SubmitForReviewInput($content, $valid['revision_id'], $this->actorId($request)));

        return EditorAnswer::to($request, $content, $this->actorId($request), __('Submitted for review.'));
    }

    public function approve(Request $request, string $content, ApproveReview $approve, SettingsReader $settings): Response
    {
        $valid = $request->validate([
            'review_id' => ['required', 'string', 'size:26'],
            // Approve and schedule (I-437): ISO 8601, as the schedule control
            // sends it; a time with no offset is read on the site's clock.
            'run_at' => ['nullable', 'date'],
        ]);

        $zone = $settings->string('general', 'timezone');
        $runAt = isset($valid['run_at']) ? CarbonImmutable::parse($valid['run_at'], $zone)->utc()->toIso8601String() : null;

        /** @var array{submittedAt: string, newerChanges: bool, scheduledFor: string|null} $approved */
        $approved = $approve->execute(new ReviewDecisionInput($valid['review_id'], $this->actorId($request), null, $runAt));

        if ($approved['scheduledFor'] !== null) {
            $when = CarbonImmutable::parse($approved['scheduledFor'])->setTimezone($zone)->format('D j M H:i');

            return EditorAnswer::to($request, $content, $this->actorId($request), __('Approved and scheduled for :when (:zone).', ['when' => $when, 'zone' => $zone]));
        }

        // What was reviewed is what was published, and the admin says so
        // plainly when the draft has moved on since.
        $message = $approved['newerChanges']
            ? __('Published the version submitted on :date. There are newer changes in the draft.', [
                'date' => CarbonImmutable::parse($approved['submittedAt'])->setTimezone($settings->string('general', 'timezone'))->format('j F'),
            ])
            : __('Approved and published.');

        return EditorAnswer::to($request, $content, $this->actorId($request), $message);
    }

    /**
     * The Return button. Not named `return`: a reserved word in JavaScript,
     * and Wayfinder names a function after each action.
     */
    public function sendBack(Request $request, string $content, ReturnForChanges $return): Response
    {
        $valid = $request->validate([
            'review_id' => ['required', 'string', 'size:26'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $return->execute(new ReviewDecisionInput($valid['review_id'], $this->actorId($request), $valid['note']));

        return EditorAnswer::to($request, $content, $this->actorId($request), __('Returned to the writer.'));
    }

    private function actorId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
