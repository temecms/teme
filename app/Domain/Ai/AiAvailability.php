<?php

/**
 * Whether an AI surface shows (I-321): AI is on for the site, the task has a
 * model that can do it, from a provider that is ready, and the person holds
 * ai.use. Shared by the admin's pages; it reads no table but the `ai` group's
 * settings row.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Models\User;

final readonly class AiAvailability
{
    public function __construct(
        private AiTasks $tasks,
        private AiProviders $providers,
        private ModelChoices $choices,
    ) {}

    /**
     * The host's switch, TEME_AI (I-315).
     */
    public function enabled(): bool
    {
        return config()->boolean('teme.ai.enabled');
    }

    /**
     * Whether the task can run for anyone: AI on, the task registered with a
     * prompt that matches its hash, and a model chosen whose provider is
     * registered and ready and which can do all the task needs.
     */
    public function taskReady(string $task): bool
    {
        if (! $this->enabled() || ! $this->tasks->has($task)) {
            return false;
        }

        $choice = $this->choices->for($task);
        $provider = $choice === null ? null : $this->providers->find($choice->primary->provider);
        $model = $choice === null ? null : $this->providers->model($choice->primary);

        return $provider !== null && $provider->ready()
            && $model !== null && $model->can(...$this->tasks->get($task)->capabilities());
    }

    /**
     * Whether this person may ask for this task now.
     */
    public function allows(?User $user, string $task): bool
    {
        return $user !== null && $user->can('ai.use') && $this->taskReady($task);
    }

    /**
     * The keys of every task this person may ask for now, in the order
     * registered.
     *
     * @return list<string>
     */
    public function tasksFor(?User $user): array
    {
        if ($user === null || ! $user->can('ai.use') || ! $this->enabled()) {
            return [];
        }

        $keys = [];

        foreach ($this->tasks->all() as $task) {
            if ($this->taskReady($task->key())) {
                $keys[] = $task->key();
            }
        }

        return $keys;
    }
}
