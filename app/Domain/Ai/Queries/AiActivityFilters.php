<?php

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\AiTasks;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The options of Tools › AI activity's three selects (I-1307): the people who may ask AI, the tasks, and the months the record holds. None scans the record: the people come from the users, the tasks from the registry and the months from the monthly totals.
 */
final readonly class AiActivityFilters
{
    public function __construct(private AiTasks $tasks, private PluginAiTasks $pluginTasks) {}

    /** The most people the select lists, as the Posts list's authors. */
    private const int MOST_PEOPLE = 500;

    /**
     * Returns the people who hold ai.use, by name, at most 500, and the one
     * chosen if they no longer do, so a link to a former asker still names
     * them.
     *
     * @return list<array{value: int, label: string}>
     */
    public function people(?int $selected): array
    {
        $people = User::permission('ai.use')->orderBy('name')->limit(self::MOST_PEOPLE)->get(['id', 'name']);

        if ($selected !== null && ! $people->contains('id', $selected)) {
            $chosen = User::query()->whereKey($selected)->first(['id', 'name']);

            if ($chosen !== null) {
                $people->push($chosen);
            }
        }

        return $people
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (User $user): array => ['value' => (int) $user->id, 'label' => $user->name])
            ->values()
            ->all();
    }

    /**
     * Returns the registered tasks by label, and the one chosen if its task
     * has gone, in its key's words.
     *
     * @return list<array{value: string, label: string}>
     */
    public function tasks(?string $selected): array
    {
        $options = [];

        foreach ($this->tasks->all() as $task) {
            $options[$task->key()] = $task->label();
        }

        if ($selected !== null && ! isset($options[$selected])) {
            $options[$selected] = AiActivityList::words($selected);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            fn (string $key, string $label): array => ['value' => $key, 'label' => $label],
            array_map(strval(...), array_keys($options)),
            array_values($options),
        );
    }

    /**
     * Returns the plugin filter's choices: Teme for core's own requests, then
     * each plugin the record holds requests from, by label, and the one
     * chosen if it holds none.
     *
     * @return list<array{value: string, label: string}>
     */
    public function plugins(?string $selected): array
    {
        $codes = DB::table('ai_activity')->whereNotNull('plugin')->distinct()->pluck('plugin')->map(fn (mixed $code): string => (string) $code)->all();

        if ($selected !== null && $selected !== AiActivityList::TEME && ! in_array($selected, $codes, true)) {
            $codes[] = $selected;
        }

        $labels = $codes === [] ? [] : $this->pluginTasks->labels();
        $options = [];

        foreach ($codes as $code) {
            $options[$code] = $labels[$code]['label'] ?? $code;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            ['value' => AiActivityList::TEME, 'label' => 'Teme'],
            ...array_map(
                fn (string $code, string $label): array => ['value' => $code, 'label' => $label],
                array_map(strval(...), array_keys($options)),
                array_values($options),
            ),
        ];
    }

    /**
     * Returns this month and each month the monthly totals hold within the 12
     * months the record is kept, newest first, and the one chosen, each as
     * 'YYYY-MM' with its words.
     *
     * @return list<array{value: string, label: string}>
     */
    public function months(?string $selected): array
    {
        $current = CarbonImmutable::now('UTC')->startOfMonth();
        $kept = DB::table('ai_monthly_spend')
            ->where('month', '>=', $current->subMonthsNoOverflow(12)->format('Y-m-d'))
            ->pluck('month')
            ->map(fn (mixed $month): string => substr((string) $month, 0, 7))
            ->all();

        $months = array_unique([$current->format('Y-m'), ...$kept, ...($selected === null ? [] : [substr($selected, 0, 7)])]);
        rsort($months);

        return array_map(
            fn (string $month): array => ['value' => $month, 'label' => CarbonImmutable::parse($month.'-01', 'UTC')->format('F Y')],
            $months,
        );
    }
}
