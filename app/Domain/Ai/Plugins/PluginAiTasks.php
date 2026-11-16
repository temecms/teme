<?php

declare(strict_types=1);

namespace App\Domain\Ai\Plugins;

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiKeys;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginKeys;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Teme\Contracts\Plugins\Capability;
use Throwable;

/**
 * The AI tasks plugins declare in `contributes.aiTasks` (I-3434): the rule
 * a declaration must meet, what the consent record keeps of them and what an
 * update adds to it, and the enabled plugins granted `ai` with their tasks,
 * for Settings › AI and the door.
 */
final readonly class PluginAiTasks
{
    /** At most this many tasks a plugin. */
    public const int MOST = 8;

    /** The longest a task's id may be. */
    public const int MOST_ID = 24;

    /** What a task may need: the capabilities that have models today. */
    public const array NEEDS = ['text', 'images-in', 'structured-output'];

    /** What a task sends, narrowest first: only what the plugin writes itself, published text, an item's unpublished text. */
    public const array SENDS = ['brief', 'published', 'draft'];

    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
    ) {}

    /**
     * Returns the task's settings stem in the `ai` group: the plugin's stem
     * and the task's id with hyphens as underscores.
     */
    public static function stem(string $code, string $task): string
    {
        return PluginKeys::stem($code).'_'.str_replace('-', '_', $task);
    }

    /**
     * Returns why a manifest's `contributes.aiTasks` can't be used, or
     * nothing when it can.
     *
     * @return list<string>
     */
    public static function errors(mixed $entries): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            return [__('Its AI tasks must be a list.')];
        }

        $errors = count($entries) > self::MOST ? [__('A plugin can declare at most 8 AI tasks.')] : [];
        $ids = [];

        foreach ($entries as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';

            foreach (array_keys($entry) as $key) {
                if (! in_array($key, ['id', 'label', 'description', 'needs', 'sends', 'maxOutputTokens'], true)) {
                    $errors[] = __('An AI task can’t have :key.', ['key' => (string) $key]);
                }
            }

            if (preg_match(AiKeys::PATTERN, $id) !== 1 || strlen($id) > self::MOST_ID) {
                $errors[] = __('An AI task’s id must be at most 24 lower-case letters and digits, in words joined by hyphens, starting with a letter.');
            } elseif (in_array($id, $ids, true)) {
                $errors[] = __('Two AI tasks can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
            $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';

            if ($label === '' || mb_strlen($label) > 40) {
                $errors[] = __('An AI task’s label must be 1 to 40 characters.');
            }

            if (array_key_exists('description', $entry) && (! is_string($entry['description']) || mb_strlen($entry['description']) > 160)) {
                $errors[] = __('An AI task’s description must be text of at most 160 characters.');
            }

            $needs = $entry['needs'] ?? null;

            if (! is_array($needs) || $needs === [] || ! array_is_list($needs) || array_diff($needs, self::NEEDS) !== [] || count(array_unique($needs, SORT_REGULAR)) !== count($needs)) {
                $errors[] = __('An AI task needs one or more of text, images-in and structured-output.');
            }

            if (! in_array($entry['sends'] ?? null, self::SENDS, true)) {
                $errors[] = __('An AI task sends brief, draft or published.');
            }

            $tokens = $entry['maxOutputTokens'] ?? null;

            if (! is_int($tokens) || $tokens < 1 || $tokens > 64_000) {
                $errors[] = __('An AI task’s maxOutputTokens must be a whole number from 1 to 64,000.');
            }
        }

        return $errors;
    }

    /**
     * Returns the tasks a manifest declares, in its order, leaving out any
     * entry the rule above refuses.
     *
     * @return list<DeclaredAiTask>
     */
    public static function of(Manifest $manifest): array
    {
        $entries = $manifest->contributes()['aiTasks'] ?? [];
        $tasks = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry) || self::errors([$entry]) !== []) {
                continue;
            }

            $tasks[] = new DeclaredAiTask(
                id: $entry['id'],
                label: trim($entry['label']),
                description: is_string($entry['description'] ?? null) ? $entry['description'] : '',
                needs: array_map(fn (string $need): AiCapability => AiCapability::from($need), $entry['needs']),
                sends: $entry['sends'],
                maxOutputTokens: $entry['maxOutputTokens'],
            );
        }

        return $tasks;
    }

    /**
     * Returns the tasks as the consent record keeps them: by id, what each
     * sends and needs.
     *
     * @return array<string, array{sends: string, needs: list<string>}>
     */
    public static function consentOf(Manifest $manifest): array
    {
        $agreed = [];

        foreach (self::of($manifest) as $task) {
            $agreed[$task->id] = ['sends' => $task->sends, 'needs' => $task->needValues()];
        }

        return $agreed;
    }

    /**
     * Returns the ids of the tasks the manifest declares that were never
     * agreed to as they stand: a new task, one that sends more than it did,
     * or one that needs something it didn't. A narrower task needs no new yes.
     *
     * @param  array<array-key, mixed>  $agreed  the consent record's `aiTasks`
     *
     * @return list<string>
     */
    public static function added(array $agreed, Manifest $manifest): array
    {
        $added = [];

        foreach (self::of($manifest) as $task) {
            $before = is_array($agreed[$task->id] ?? null) ? $agreed[$task->id] : null;
            $sentBefore = array_search($before['sends'] ?? null, self::SENDS, true);
            $neededBefore = is_array($before['needs'] ?? null) ? $before['needs'] : [];

            if ($before === null || $sentBefore === false || array_search($task->sends, self::SENDS, true) > $sentBefore || array_diff($task->needValues(), $neededBefore) !== []) {
                $added[] = $task->id;
            }
        }

        return $added;
    }

    /**
     * Returns the enabled plugins granted `ai` that declare a task, by code,
     * ordered by name: each one's label and its tasks. Nothing when the
     * plugins can't be read, so Settings › AI never fails for them.
     *
     * @return array<string, array{name: string, label: string, tasks: list<DeclaredAiTask>}>
     */
    public function enabled(): array
    {
        try {
            if (! $this->registry->anyInstalled()) {
                return [];
            }

            $records = $this->records->enabled();
            $plugins = [];

            foreach ($records as $record) {
                if (! in_array(Capability::Ai->value, $record->consent['capabilities'] ?? [], true)) {
                    continue;
                }

                $manifest = $this->registry->find($record->name)?->manifest;
                $tasks = $manifest === null ? [] : self::of($manifest);

                if ($manifest !== null && $tasks !== []) {
                    $plugins[$manifest->code()] = ['name' => $record->name, 'label' => $manifest->label(), 'tasks' => $tasks];
                }
            }

            return $plugins;
        } catch (Throwable $failure) {
            report($failure);

            return [];
        }
    }

    /**
     * Returns every installed plugin's label and its AI tasks' labels, by
     * code, for naming a plugin's requests in the record; nothing when the
     * plugins can't be read.
     *
     * @return array<string, array{label: string, tasks: array<string, string>}>
     */
    public function labels(): array
    {
        try {
            $labels = [];

            foreach ($this->registry->all() as $found) {
                $manifest = $found->manifest;

                if ($manifest === null) {
                    continue;
                }

                $tasks = [];

                foreach (self::of($manifest) as $task) {
                    $tasks[$task->id] = $task->label;
                }

                $labels[$manifest->code()] = ['label' => $manifest->label(), 'tasks' => $tasks];
            }

            return $labels;
        } catch (Throwable $failure) {
            report($failure);

            return [];
        }
    }

    /**
     * Returns the task an enabled plugin granted `ai` declares, or null.
     */
    public function declared(string $code, string $task): ?DeclaredAiTask
    {
        foreach ($this->enabled()[$code]['tasks'] ?? [] as $declared) {
            if ($declared->id === $task) {
                return $declared;
            }
        }

        return null;
    }
}
