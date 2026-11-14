<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Operations\CancelScheduledPublication;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Scheduling an article's publication, and cancelling it, through
 * SchedulePublication and CancelScheduledPublication. The editor asks for
 * JSON and gets the article's new state; anything else goes back with a
 * message.
 */
final class ScheduleController extends Controller
{
    public function store(Request $request, string $content, SchedulePublication $schedule, SettingsReader $settings): Response
    {
        $valid = $request->validate([
            'revision_id' => ['required', 'string', 'size:26'],
            'action' => ['required', 'in:publish,update'],
            // ISO 8601 with its offset, as the schedule control sends it.
            'run_at' => ['required', 'date'],
        ]);

        $schedule->execute(new SchedulePublicationInput(
            contentId: $content,
            revisionId: $valid['revision_id'],
            action: $valid['action'],
            runAt: $valid['run_at'],
            actorId: $this->actorId($request),
        ));

        $zone = $settings->string('general', 'timezone');
        $when = CarbonImmutable::parse($valid['run_at'])->setTimezone($zone)->format('D j M H:i');

        return EditorAnswer::to($request, $content, $this->actorId($request), __('Scheduled for :when (:zone).', ['when' => $when, 'zone' => $zone]));
    }

    public function destroy(Request $request, string $content, CancelScheduledPublication $cancel): Response
    {
        $cancel->execute(new ContentActionInput($content, $this->actorId($request)));

        return EditorAnswer::to($request, $content, $this->actorId($request), __('Schedule cancelled.'));
    }

    private function actorId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
