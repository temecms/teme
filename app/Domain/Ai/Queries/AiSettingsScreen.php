<?php

/**
 * Settings › AI's data (I-1308): the group's sections in their order, each
 * service with its key's state and never its key, each task with the models
 * able to do it and why it is off, the limits with this month's figures, and
 * the values and version to save against. While the server has AI switched
 * off, only that.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiModel;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSettingsSection;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\Plugins\DeclaredAiTask;
use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Ai\Plugins\PluginAiSpend;
use App\Domain\Ai\Plugins\PluginAiStanding;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Ai\Providers\LibraryProvider;
use App\Domain\Ai\UsageLimits;
use App\Domain\Plugins\PluginWords;
use App\Domain\Settings\Queries\SettingsForm;
use App\Models\User;
use RuntimeException;

final readonly class AiSettingsScreen
{
    /** Core's words under each of core's tasks, by task key; another part's task has none. */
    public const array TASK_NOTES = [
        'rewrite' => 'The Rewrite menu on text you select in the editor.',
        'alt-text' => 'Suggest alt text, in an image’s details. Only models that can read images are offered.',
        'metadata' => 'Suggest metadata, in the editor’s Search and social section.',
    ];

    /** This screen's hint for a service's other model, where its models are chosen from a list. */
    private const string OTHER_MODEL_HINT = 'A model Teme’s list doesn’t have yet, written as %s writes it. Once saved, it’s offered under Models for the tasks it can do.';

    public function __construct(
        private SettingsForm $form,
        private AiSettings $settings,
        private AiTasks $tasks,
        private AiProviders $providers,
        private ModelChoices $choices,
        private AiAvailability $availability,
        private AiMonthSummary $month,
        private PluginAiTasks $pluginTasks,
        private PluginAiStanding $pluginStanding,
        private PluginAiSpend $pluginSpend,
    ) {}

    /**
     * Returns Settings › AI as its page needs it: whether AI is on and, only
     * when it is, the group's description, whether any task can run, the
     * sections in their order (each service, the models, the plugins' tasks
     * and limits, the limits, and any other part's section on the generic
     * controls), this month's figures,
     * Tools › AI activity's address for whoever may see it, the group's
     * values without its secrets, each secret's state, each media setting's
     * image, and the version to save against.
     *
     * @return array<string, mixed>
     */
    public function data(User $viewer): array
    {
        if (! $this->availability->enabled()) {
            return ['enabled' => false];
        }

        $form = $this->form->find(AiSettings::KEY) ?? throw new RuntimeException('No settings group ['.AiSettings::KEY.'] is registered.');
        $fields = array_column($form['fields'], null, 'key');

        // Each provider's readiness and models, read once for every task.
        $catalogue = array_map(fn (AiProvider $provider): array => [
            'provider' => $provider,
            'ready' => $provider->ready(),
            'models' => $provider->models(),
        ], $this->providers->all());

        $sections = [];

        foreach ($this->settings->ordered() as $section) {
            $drawn = match (true) {
                $section instanceof LibraryProvider => $this->service($section, $fields),
                $section instanceof PluginAiSection => $this->plugins($section, $fields, $catalogue),
                $section instanceof ModelChoices => [
                    'kind' => 'models',
                    'key' => $section->key(),
                    'label' => $section->label(),
                    'tasks' => array_map(fn (AiTask $task): array => $this->task($task, $catalogue), $this->tasks->all()),
                ],
                default => [
                    'kind' => $section instanceof UsageLimits ? 'limits' : 'other',
                    'key' => $section->key(),
                    'label' => $section->label(),
                    'fields' => $this->fieldsOf($section, $fields),
                ],
            };

            if ($drawn !== null) {
                $sections[] = $drawn;
            }
        }

        return [
            'enabled' => true,
            'description' => $form['group']['description'],
            'anyTaskOn' => array_any($this->tasks->all(), fn (AiTask $task): bool => $this->availability->taskReady($task->key())),
            'sections' => $sections,
            'month' => $this->month->of(null),
            'activityUrl' => $viewer->can('ai-activity.view') ? route('tools.ai-activity.index') : null,
            'values' => $form['values'],
            'secrets' => $form['secrets'],
            'media' => $form['media'],
            'version' => $form['version'],
        ];
    }

    /**
     * A service: its words, its key's state and never its key, and its other
     * settings, the other model's name with this screen's hint.
     *
     * @param  array<array-key, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    private function service(LibraryProvider $provider, array $fields): array
    {
        $state = $provider->keyState();
        $own = $this->fieldsOf($provider, $fields, $provider->keySetting());
        $otherModel = str_replace('-', '_', $provider->key()).'_other_model';

        foreach ($own as $index => $field) {
            if (($field['key'] ?? null) === $otherModel) {
                $own[$index]['description'] = sprintf(self::OTHER_MODEL_HINT, $provider->label());
            }
        }

        return [
            'kind' => 'service',
            'key' => $provider->key(),
            'label' => $provider->label(),
            'state' => $state->value,
            'stateLabel' => $state->label(),
            'variable' => $provider->environmentVariable(),
            'keyField' => $provider->keySetting(),
            'fields' => $own,
        ];
    }

    /**
     * Settings › AI's Plugins section: its settings on the generic controls,
     * and each enabled plugin granted `ai` with its switch's, budget's and
     * limit's settings, its month so far, and its tasks' rows, each with what
     * it sends, its two settings, every model able to do it, and whether it
     * can be asked now and, when it can't, why.
     *
     * @param  array<array-key, mixed>  $fields
     * @param  list<array{provider: AiProvider, ready: bool, models: list<AiModel>}>  $catalogue
     *
     * @return array<string, mixed>|null null when no enabled plugin declares an AI task, so the section isn't drawn
     */
    private function plugins(PluginAiSection $section, array $fields, array $catalogue): ?array
    {
        $plugins = [];

        foreach ($this->pluginTasks->enabled() as $code => $plugin) {
            $month = $this->pluginSpend->month($code);
            $plugins[] = [
                'code' => $code,
                'label' => $plugin['label'],
                'onField' => PluginAiSection::onKey($code),
                'budgetField' => PluginAiSection::budgetKey($code),
                'hourlyField' => PluginAiSection::hourlyKey($code),
                'on' => $section->on($code),
                'budgetMicrounits' => $section->budgetMicrounits($code),
                'hourly' => $section->hourly($code),
                'month' => ['costMicrounits' => $month->costMicrounits, 'requests' => $month->requests, 'unpricedRequests' => $month->unpricedRequests],
                'tasks' => array_map(function (DeclaredAiTask $task) use ($code, $catalogue): array {
                    $standing = $this->pluginStanding->of($code, $task);

                    return [
                        'key' => $task->id,
                        'label' => $task->label,
                        'note' => $task->description === '' ? null : $task->description,
                        'sends' => $task->sends,
                        'sendsWords' => PluginWords::aiSends($task->sends),
                        'modelField' => PluginAiSection::modelKey($code, $task->id),
                        'fallbackField' => PluginAiSection::fallbackKey($code, $task->id),
                        'on' => $standing->available,
                        'status' => $standing->available ? __('On') : __('Off: :why', ['why' => $standing->sentence]),
                        'options' => $this->options($task->needs, $catalogue),
                    ];
                }, $plugin['tasks']),
            ];
        }

        if ($plugins === []) {
            return null;
        }

        return [
            'kind' => 'plugins',
            'key' => $section->key(),
            'label' => $section->label(),
            'fields' => $this->fieldsOf($section, $fields),
            'plugins' => $plugins,
        ];
    }

    /**
     * A task's row: its words, its two settings, every model able to do it
     * from every provider, whether it can run and, when it can't, why.
     *
     * @param  list<array{provider: AiProvider, ready: bool, models: list<AiModel>}>  $catalogue
     *
     * @return array<string, mixed>
     */
    private function task(AiTask $task, array $catalogue): array
    {
        return [
            'key' => $task->key(),
            'label' => $task->label(),
            'note' => self::TASK_NOTES[$task->key()] ?? null,
            'modelField' => ModelChoices::modelKey($task->key()),
            'fallbackField' => ModelChoices::fallbackKey($task->key()),
            'on' => $this->availability->taskReady($task->key()),
            'status' => $this->status($task),
            'options' => $this->options($task->capabilities(), $catalogue),
        ];
    }

    /**
     * Every model able to do all of $needs, from every provider, as a select offers it.
     *
     * @param  list<AiCapability>  $needs
     * @param  list<array{provider: AiProvider, ready: bool, models: list<AiModel>}>  $catalogue
     *
     * @return list<array<string, mixed>>
     */
    private function options(array $needs, array $catalogue): array
    {
        $options = [];

        foreach ($catalogue as $entry) {
            $provider = $entry['provider'];

            foreach ($entry['models'] as $model) {
                if (! $model->can(...$needs)) {
                    continue;
                }

                $options[] = [
                    'value' => $provider->key().':'.$model->id,
                    'label' => $provider->label().': '.$model->label,
                    'provider' => $provider->label(),
                    'ready' => $entry['ready'],
                    'input' => $model->price?->input,
                    'output' => $model->price?->output,
                    'note' => $provider instanceof LibraryProvider ? $provider->reasoning($model->id)->note() : null,
                ];
            }
        }

        return $options;
    }

    /**
     * "On", or the first reason the task can't run, checked in the order
     * AiAvailability::taskReady() needs them.
     */
    private function status(AiTask $task): string
    {
        $label = $task->label();

        if (! $this->tasks->has($task->key())) {
            return "Off: {$label} was changed without a new version number, so Teme won’t run it.";
        }

        $choice = $this->choices->for($task->key());

        if ($choice === null) {
            return 'Off: no model is chosen.';
        }

        $provider = $this->providers->find($choice->primary->provider);
        $model = $this->providers->model($choice->primary);

        if ($provider === null || $model === null) {
            return 'Off: the model chosen for it is no longer offered. Choose another model.';
        }

        if (! $model->can(...$task->capabilities())) {
            return "Off: {$model->label} can’t do everything {$label} needs. Choose another model.";
        }

        if (! $provider->ready()) {
            return "Off: {$provider->label()} has no key. Set one under AI services.";
        }

        return 'On';
    }

    /**
     * The generic form's fields for a section's settings, in the section's
     * order, leaving out the one named.
     *
     * @param  array<array-key, mixed>  $fields
     *
     * @return list<array<array-key, mixed>>
     */
    private function fieldsOf(AiSettingsSection $section, array $fields, ?string $except = null): array
    {
        $drawn = [];

        foreach ($section->settings() as $setting) {
            $field = $fields[$setting->key] ?? null;

            if ($setting->key !== $except && is_array($field)) {
                $drawn[] = $field;
            }
        }

        return $drawn;
    }
}
