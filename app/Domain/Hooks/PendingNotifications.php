<?php

declare(strict_types=1);

namespace App\Domain\Hooks;

use Closure;
use LogicException;
use Teme\Contracts\Hooks\Notification;

/**
 * What the running Operations announce to plugins (I-3353): a list for each
 * Operation executing, nested as Operations nest, which BaseOperation opens
 * before perform(), closes after it, and delivers after the outermost
 * commit, never after a rollback (rule 6). Only what a plugin listens for
 * is kept. Scoped to a request or a job.
 */
final class PendingNotifications
{
    /** @var array<int, list<array{0: class-string<Notification>, 1: Closure(): Notification}>> */
    private array $open = [];

    public function __construct(private readonly HookDispatcher $dispatcher) {}

    /**
     * Opens a list for an Operation about to perform.
     */
    public function open(): void
    {
        $this->open[] = [];
    }

    /**
     * Adds a notification to the innermost Operation's list, to be built
     * after commit, when a plugin listens for it.
     *
     * @param  class-string<Notification>  $hook
     * @param  Closure(): Notification  $build
     *
     * @throws LogicException outside an Operation: only an Operation announces a change (rule 1)
     */
    public function add(string $hook, Closure $build): void
    {
        $innermost = array_key_last($this->open);

        if ($innermost === null) {
            throw new LogicException('Only an Operation announces a change (rule 1): announce it from inside perform().');
        }

        if ($this->dispatcher->wants($hook)) {
            $this->open[$innermost][] = [$hook, $build];
        }
    }

    /**
     * Closes the innermost Operation's list, and returns what it announced, in order.
     *
     * @return list<array{0: class-string<Notification>, 1: Closure(): Notification}>
     */
    public function close(): array
    {
        return array_pop($this->open) ?? [];
    }
}
