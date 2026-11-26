<?php

/**
 * Tools › AI activity (I-1307): every AI request in the record, a page at a
 * time, narrowed by person, task and month, with the month's spend above it
 * and a drawer that has its own address. It reads only; nothing here changes
 * anything, and a filter it cannot read is ignored.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiKeys;
use App\Domain\Ai\Queries\AiActivityFilters;
use App\Domain\Ai\Queries\AiActivityList;
use App\Domain\Ai\Queries\AiActivityRequest;
use App\Domain\Ai\Queries\AiMonthSummary;
use App\Domain\Ai\Tasks\AltText\Queries\AltTextChanges;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

final class AiActivityController extends Controller
{
    public function __invoke(Request $request, AiActivityList $list, AiActivityFilters $filters, AiMonthSummary $summary, AiAvailability $availability, SettingsReader $settings, AltTextChanges $altTextChanges): Response
    {
        $person = self::person($request);
        $task = self::task($request);
        $month = self::month($request);
        $plugin = self::plugin($request);
        $id = $request->string('row')->toString();

        // A row's ID is 26 lower-case letters and digits (I-418); anything else is no address of ours.
        abort_if($id !== '' && preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) !== 1, 404);

        $open = $id === '' ? null : $list->find($id);

        // On a used alt-text request, the alt text from before and after (I-1312).
        if ($open !== null) {
            $open['altText'] = $altTextChanges->for($id);
        }

        $page = null;

        // One read serves the rows and both cursors, and none when only the drawer is asked for again.
        $read = function () use (&$page, $list, $request, $person, $task, $month, $plugin): array {
            return $page ??= $list->page(new AiActivityRequest(
                person: $person,
                task: $task,
                month: $month,
                after: self::optional($request, 'after'),
                before: self::optional($request, 'before'),
                perPage: (int) PreferencesFor::user($request->user())['lists.per_page'],
                plugin: $plugin,
            ));
        };

        return Inertia::render('tools/ai-activity', [
            'rows' => fn (): array => $read()['rows'],
            'newer' => fn (): ?string => $read()['newer'],
            'older' => fn (): ?string => $read()['older'],
            'from' => fn (): ?int => $read()['from'],
            'to' => fn (): ?int => $read()['to'],
            'total' => fn (): ?int => $read()['total'],
            'filters' => fn (): array => ['person' => $person, 'task' => $task, 'month' => $month === null ? null : substr($month, 0, 7), 'plugin' => $plugin],
            'people' => fn (): array => $filters->people($person),
            'tasks' => fn (): array => $filters->tasks($task),
            'months' => fn (): array => $filters->months($month),
            'plugins' => fn (): array => $filters->plugins($plugin),
            'summary' => fn (): array => $summary->of($month),
            'pluginMonths' => fn (): array => $summary->byPlugin($month),
            'details' => $open,
            'missing' => $id !== '' && $open === null,
            'siteTimezone' => $settings->string('general', 'timezone'),
            'aiOn' => $availability->enabled(),
            'canChangeSettings' => $request->user()?->can('settings.ai.manage') === true,
        ]);
    }

    /**
     * A user's number from the address, or null when it isn't one.
     */
    private static function person(Request $request): ?int
    {
        $value = $request->string('person')->toString();

        return preg_match('/^\d{1,18}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * A task's key from the address, or null when it isn't one.
     */
    private static function task(Request $request): ?string
    {
        $value = $request->string('task')->toString();

        return mb_strlen($value) <= 64 && preg_match(AiKeys::PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * A plugin's code from the address, or `teme` for core's own requests,
     * or null when it is neither.
     */
    private static function plugin(Request $request): ?string
    {
        $value = $request->string('plugin')->toString();

        return preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $value) === 1 ? $value : null;
    }

    /**
     * A month as the address writes it, 2026-11, as its first day, or null
     * when it isn't one.
     */
    private static function month(Request $request): ?string
    {
        $value = $request->string('month')->toString();

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 ? $value.'-01' : null;
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
