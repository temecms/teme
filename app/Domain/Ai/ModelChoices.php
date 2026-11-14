<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Settings\SettingsCheck;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Settings › AI's section "Models" (I-315): a model and a fallback for every
 * registered task, each written provider:model, and the check that each names
 * a registered provider, a model it lists and a model able to do what the
 * task needs. Also how the rest of Teme reads a task's choice.
 */
final readonly class ModelChoices implements AiSettingsSection, SettingsCheck
{
    public function __construct(
        private AiTasks $tasks,
        private AiProviders $providers,
        private SettingsReader $settings,
    ) {}

    public function order(): int
    {
        return 200;
    }

    public function key(): string
    {
        return 'models';
    }

    public function label(): string
    {
        return 'Models';
    }

    public function settings(): array
    {
        $settings = [];

        foreach ($this->tasks->all() as $task) {
            $label = $task->label();
            $settings[] = Setting::text(self::modelKey($task->key()), "Model for {$label}", "The provider and model that answer, written provider:model. Empty switches {$label} off.", '0.1.0')->maxLength(200);
            $settings[] = Setting::text(self::fallbackKey($task->key()), "Fallback for {$label}", 'Asked once when the first model can’t be reached or is busy, written provider:model. Optional.', '0.1.0')->maxLength(200);
        }

        return $settings;
    }

    public static function modelKey(string $task): string
    {
        return str_replace('-', '_', $task).'_model';
    }

    public static function fallbackKey(string $task): string
    {
        return str_replace('-', '_', $task).'_fallback';
    }

    /**
     * A registered task's model and fallback, or null when no model is chosen
     * or the choice can't be read.
     */
    public function for(string $task): ?ModelChoice
    {
        $primary = ModelRef::parse($this->settings->string(AiSettings::KEY, self::modelKey($task)));

        if ($primary === null) {
            return null;
        }

        return new ModelChoice($primary, ModelRef::parse($this->settings->string(AiSettings::KEY, self::fallbackKey($task))));
    }

    public function group(): string
    {
        return AiSettings::KEY;
    }

    public function problems(array $values): array
    {
        $problems = [];

        foreach ($this->tasks->all() as $task) {
            $modelKey = self::modelKey($task->key());
            $fallbackKey = self::fallbackKey($task->key());
            $model = $this->value($values, $modelKey);
            $fallback = $this->value($values, $fallbackKey);

            if (array_key_exists($modelKey, $values) && $model !== '') {
                $problem = $this->problem($model, $task);

                if ($problem !== null) {
                    $problems["values.{$modelKey}"] = $problem;
                }
            }

            if (array_key_exists($fallbackKey, $values) && $fallback !== '') {
                $same = ModelRef::parse($fallback)?->toString();

                $problem = match (true) {
                    $model === '' => 'Choose a model before its fallback.',
                    $same !== null && $same === ModelRef::parse($model)?->toString() => 'Choose a fallback that differs from the model.',
                    default => $this->problem($fallback, $task),
                };

                if ($problem !== null) {
                    $problems["values.{$fallbackKey}"] = $problem;
                }
            }
        }

        return $problems;
    }

    /**
     * A value as the form sent it, or as stored when the form left it out; a
     * value that isn't text is left to its type's own rule.
     *
     * @param  array<string, mixed>  $values
     */
    private function value(array $values, string $key): string
    {
        $value = array_key_exists($key, $values) ? $values[$key] : $this->settings->string(AiSettings::KEY, $key);

        return is_string($value) ? trim($value) : '';
    }

    private function problem(string $value, AiTask $task): ?string
    {
        $ref = ModelRef::parse($value);

        if ($ref === null) {
            return 'Write the model as provider:model.';
        }

        $provider = $this->providers->find($ref->provider);

        if ($provider === null) {
            return "Teme has no AI provider called {$ref->provider}.";
        }

        $model = $this->providers->model($ref);

        if ($model === null) {
            return "{$provider->label()} has no model called {$ref->model}.";
        }

        return $model->can(...$task->capabilities()) ? null : "{$model->label} can’t do everything {$task->label()} needs.";
    }
}
