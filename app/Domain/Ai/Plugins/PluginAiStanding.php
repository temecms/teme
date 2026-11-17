<?php

declare(strict_types=1);

namespace App\Domain\Ai\Plugins;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\Queries\AiSpend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Ai\AiStanding;

/**
 * Whether a plugin's declared task can be asked now, and why not in words
 * the plugin may show on its own pages: AI on for the site and for the
 * plugin, a model chosen that can do the task, from a provider that is
 * ready, neither budget reached, and the plugin under its hourly limit.
 * Budgets and the limit are soft: requests already running may pass them.
 */
final readonly class PluginAiStanding
{
    public function __construct(
        private AiAvailability $availability,
        private AiProviders $providers,
        private ModelChoices $choices,
        private AiSpend $spend,
        private PluginAiSpend $pluginSpend,
        private PluginAiSection $section,
    ) {}

    /**
     * Returns where this plugin's task stands now, with the plugin's month.
     */
    public function of(string $code, DeclaredAiTask $task): AiStanding
    {
        $month = $this->pluginSpend->month($code);
        $budget = $this->section->budgetMicrounits($code);
        [$reason, $sentence] = $this->reason($code, $task, $month->costMicrounits, $budget);

        return new AiStanding($reason === 'ready', $reason, $sentence, $month->costMicrounits, $month->requests, $budget);
    }

    /**
     * Returns the plugin's requests recorded in the last hour.
     */
    public function lastHour(string $code): int
    {
        return DB::table('ai_activity')->where('plugin', $code)->where('created_at', '>', CarbonImmutable::now()->subHour())->count();
    }

    /**
     * The first reason it can't be asked, or ready.
     *
     * @return array{0: string, 1: string}
     */
    private function reason(string $code, DeclaredAiTask $task, int $monthCost, ?int $budget): array
    {
        if (! $this->availability->enabled()) {
            return ['ai-off', __('AI is switched off on this site.')];
        }

        if (! $this->section->on($code)) {
            return ['plugin-off', __('AI is switched off for this plugin in Settings › AI.')];
        }

        $choice = $this->choices->forStem(PluginAiTasks::stem($code, $task->id));

        if ($choice === null) {
            return ['no-model', __(':task is off until an administrator chooses its model in Settings › AI.', ['task' => $task->label])];
        }

        $provider = $this->providers->find($choice->primary->provider);
        $model = $this->providers->model($choice->primary);

        if ($provider === null || ! $provider->ready() || $model === null || ! $model->can(...$task->needs)) {
            return ['model-lacks', __('The model chosen for :task can’t be used for it now. An administrator can choose another in Settings › AI.', ['task' => $task->label])];
        }

        if ($this->spend->budgetReached()) {
            return ['site-budget', __('The site’s AI budget for this month is spent.')];
        }

        if ($budget !== null && $monthCost >= $budget) {
            return ['plugin-budget', __('This plugin’s AI budget for this month is spent.')];
        }

        if ($this->lastHour($code) >= $this->section->hourly($code)) {
            return ['hourly-limit', __('This plugin has asked AI as many times as it may this hour.')];
        }

        return ['ready', ''];
    }
}
