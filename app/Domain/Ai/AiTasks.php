<?php

/**
 * Every registered AI task (I-316), read through the container each time,
 * so it holds no state. A task whose prompt no longer matches its hash is
 * stopped: has() is false and get() throws, and nothing else is.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class AiTasks
{
    /** The container tag a task is registered under, from its part's own provider's register(). */
    public const string TAG = 'teme.ai.tasks';

    public function __construct(private Container $container) {}

    /**
     * Every task, in the order registered, each key checked.
     *
     * @return list<AiTask>
     */
    public function all(): array
    {
        $tasks = [];
        $seen = [];

        foreach ($this->container->tagged(self::TAG) as $task) {
            if (! $task instanceof AiTask) {
                throw new UnexpectedValueException('Registered as an AI task, but '.get_debug_type($task).' isn\'t one.');
            }

            AiKeys::check('AI task', $task->key(), $seen);
            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * Whether the task is registered and its prompt matches its hash.
     */
    public function has(string $key): bool
    {
        return $this->find($key)?->prompt()->matches() ?? false;
    }

    public function get(string $key): AiTask
    {
        $task = $this->find($key) ?? throw new InvalidArgumentException("No AI task has the key [{$key}].");

        if (! $task->prompt()->matches()) {
            throw new UnexpectedValueException("AI task [{$key}]'s prompt doesn't match its hash; a changed prompt is a new version.");
        }

        return $task;
    }

    private function find(string $key): ?AiTask
    {
        foreach ($this->all() as $task) {
            if ($task->key() === $key) {
                return $task;
            }
        }

        return null;
    }
}
