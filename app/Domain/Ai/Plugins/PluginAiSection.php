<?php

/**
 * Settings › AI's section "Plugins" (I-3434): for each enabled plugin granted
 * `ai`, its tasks' models and fallbacks, and its own switch, monthly budget
 * and requests an hour; and the check on what a form sends for them. Also how
 * the rest of Teme reads a plugin's switch, budget and limit.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Plugins;

use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSettingsSection;
use App\Domain\Ai\ModelRef;
use App\Domain\Ai\UsageLimits;
use App\Domain\Plugins\PluginKeys;
use App\Domain\Settings\SettingsCheck;
use InvalidArgumentException;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use UnexpectedValueException;

final readonly class PluginAiSection implements AiSettingsSection, SettingsCheck
{
    /** A plugin's requests an hour, until an administrator changes it. */
    public const int HOURLY = 120;

    public function __construct(
        private PluginAiTasks $tasks,
        private AiProviders $providers,
        private SettingsReader $settings,
    ) {}

    public function order(): int
    {
        return 260;
    }

    public function key(): string
    {
        return 'plugins';
    }

    public function label(): string
    {
        return __('Plugins');
    }

    public static function onKey(string $code): string
    {
        return PluginKeys::stem($code).'_on';
    }

    public static function budgetKey(string $code): string
    {
        return PluginKeys::stem($code).'_budget';
    }

    public static function hourlyKey(string $code): string
    {
        return PluginKeys::stem($code).'_hourly';
    }

    public static function modelKey(string $code, string $task): string
    {
        return PluginAiTasks::stem($code, $task).'_model';
    }

    public static function fallbackKey(string $code, string $task): string
    {
        return PluginAiTasks::stem($code, $task).'_fallback';
    }

    public function settings(): array
    {
        $settings = [];

        foreach ($this->tasks->enabled() as $code => $plugin) {
            $name = $plugin['label'];
            $settings[] = Setting::boolean(self::onKey($code), __('AI for :plugin', ['plugin' => $name]), __('Lets :plugin ask the site’s AI connection for its tasks. On once the plugin is enabled.', ['plugin' => $name]), '1.0.0')
                ->default(true);
            $settings[] = Setting::number(self::budgetKey($code), __(':plugin’s monthly budget, in US dollars', ['plugin' => $name]), __('When :plugin’s recorded cost this month reaches it, AI is unavailable to :plugin until the month ends. 0 means only the site’s budget applies.', ['plugin' => $name]), '1.0.0')
                ->default(0)->min(0)->max(1_000_000);
            $settings[] = Setting::number(self::hourlyKey($code), __(':plugin’s requests an hour', ['plugin' => $name]), __('How many times :plugin may ask AI in an hour, for everyone it works for.', ['plugin' => $name]), '1.0.0')
                ->default(self::HOURLY)->min(1)->max(10_000);

            foreach ($plugin['tasks'] as $task) {
                $settings[] = Setting::text(self::modelKey($code, $task->id), __('Model for :task (:plugin)', ['task' => $task->label, 'plugin' => $name]), __('The provider and model that answer, written provider:model. Empty switches :task off.', ['task' => $task->label]), '1.0.0')
                    ->maxLength(200);
                $settings[] = Setting::text(self::fallbackKey($code, $task->id), __('Fallback for :task (:plugin)', ['task' => $task->label, 'plugin' => $name]), __('Asked once when the first model can’t be reached or is busy, written provider:model. Optional.'), '1.0.0')
                    ->maxLength(200);
            }
        }

        return $settings;
    }

    /**
     * Returns whether AI is on for this plugin: on until an administrator
     * switches it off.
     */
    public function on(string $code): bool
    {
        return $this->read(fn (): bool => $this->settings->bool(AiSettings::KEY, self::onKey($code)), true);
    }

    /**
     * Returns the plugin's own monthly budget in micro-dollars, or null when
     * only the site's applies.
     */
    public function budgetMicrounits(string $code): ?int
    {
        $dollars = $this->read(fn (): int => $this->settings->int(AiSettings::KEY, self::budgetKey($code)), 0);

        return $dollars > 0 ? $dollars * 1_000_000 : null;
    }

    /**
     * Returns how many requests the plugin may make in an hour.
     */
    public function hourly(string $code): int
    {
        return $this->read(fn (): int => $this->settings->int(AiSettings::KEY, self::hourlyKey($code)), self::HOURLY);
    }

    public function group(): string
    {
        return AiSettings::KEY;
    }

    public function problems(array $values): array
    {
        $problems = [];
        $site = $this->siteBudget($values);

        foreach ($this->tasks->enabled() as $code => $plugin) {
            $budgetKey = self::budgetKey($code);
            $budget = $values[$budgetKey] ?? null;

            if (is_int($budget) && $budget > 0 && $site > 0 && $budget > $site) {
                $problems["values.{$budgetKey}"] = __('Choose a budget no larger than the site’s, :budget US dollars.', ['budget' => $site]);
            }

            foreach ($plugin['tasks'] as $task) {
                $problems = [...$problems, ...$this->modelProblems($values, $code, $task)];
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @return array<string, string>
     */
    private function modelProblems(array $values, string $code, DeclaredAiTask $task): array
    {
        $problems = [];
        $modelKey = self::modelKey($code, $task->id);
        $fallbackKey = self::fallbackKey($code, $task->id);
        $model = $this->text($values, $modelKey);
        $fallback = $this->text($values, $fallbackKey);

        if (array_key_exists($modelKey, $values) && $model !== '') {
            $problem = $this->problem($model, $task);

            if ($problem !== null) {
                $problems["values.{$modelKey}"] = $problem;
            }
        }

        if (array_key_exists($fallbackKey, $values) && $fallback !== '') {
            $same = ModelRef::parse($fallback)?->toString();

            $problem = match (true) {
                $model === '' => __('Choose a model before its fallback.'),
                $same !== null && $same === ModelRef::parse($model)?->toString() => __('Choose a fallback that differs from the model.'),
                default => $this->problem($fallback, $task),
            };

            if ($problem !== null) {
                $problems["values.{$fallbackKey}"] = $problem;
            }
        }

        return $problems;
    }

    private function problem(string $value, DeclaredAiTask $task): ?string
    {
        $ref = ModelRef::parse($value);

        if ($ref === null) {
            return __('Write the model as provider:model.');
        }

        $provider = $this->providers->find($ref->provider);

        if ($provider === null) {
            return __('Teme has no AI provider called :provider.', ['provider' => $ref->provider]);
        }

        $model = $this->providers->model($ref);

        if ($model === null) {
            return __(':provider has no model called :model.', ['provider' => $provider->label(), 'model' => $ref->model]);
        }

        return $model->can(...$task->needs) ? null : __(':model can’t do everything :task needs.', ['model' => $model->label, 'task' => $task->label]);
    }

    /**
     * The site's budget in whole dollars, as the form sends it or as stored; 0 for none.
     *
     * @param  array<string, mixed>  $values
     */
    private function siteBudget(array $values): int
    {
        $sent = $values[UsageLimits::BUDGET] ?? null;

        return is_int($sent) ? $sent : $this->read(fn (): int => $this->settings->int(AiSettings::KEY, UsageLimits::BUDGET), 0);
    }

    /**
     * A text value as the form sent it, or as stored when the form left it out.
     *
     * @param  array<string, mixed>  $values
     */
    private function text(array $values, string $key): string
    {
        $value = array_key_exists($key, $values) ? $values[$key] : $this->read(fn (): string => $this->settings->string(AiSettings::KEY, $key), '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * A stored value, or the default when the setting isn't registered yet:
     * the group is built once a process, and a plugin enabled since adds its
     * settings only when it is built again.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @param  T  $default
     *
     * @return T
     */
    private function read(callable $read, mixed $default): mixed
    {
        try {
            return $read();
        } catch (InvalidArgumentException|UnexpectedValueException) {
            return $default;
        }
    }
}
