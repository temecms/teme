<?php

/**
 * Where an answer waits for the page that asked (I-320): one entry per
 * request in Laravel's disposable cache (I-261), arrays only, for 15
 * minutes, and a separate entry for a cancel, so the job and the person
 * never overwrite each other. Forgotten once the suggestion is settled.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking;

use App\Domain\Ai\AiSubject;
use Closure;
use Illuminate\Contracts\Cache\Repository;

final readonly class AiSuggestions
{
    public const int TTL_MINUTES = 15;

    public function __construct(private Repository $cache) {}

    /**
     * @param  list<string>  $sentTo
     */
    public function start(string $id, string $userId, string $task, AiSubject $subject, array $sentTo): void
    {
        $this->put(new Suggestion($id, $userId, $task, $subject, Suggestion::WORKING, sentTo: $sentTo));
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $sentTo
     */
    public function ready(string $id, array $values, ?string $hash, array $sentTo): void
    {
        $this->update($id, fn (Suggestion $suggestion): Suggestion => $suggestion->with(Suggestion::READY, $values, $hash, null, $sentTo));
    }

    /**
     * @param  list<string>  $sentTo  who it was sent to; empty keeps what the asking said
     */
    public function fail(string $id, string $reason, array $sentTo): void
    {
        $this->update($id, fn (Suggestion $suggestion): Suggestion => $suggestion->with(Suggestion::FAILED, [], null, $reason, $sentTo === [] ? $suggestion->sentTo : $sentTo));
    }

    public function cancel(string $id): void
    {
        $this->cache->put(self::key($id).'.cancelled', true, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Whether the person cancelled the request: read afresh at every look,
     * since a cancel can arrive while the job runs.
     *
     * @phpstan-impure
     */
    public function cancelled(string $id): bool
    {
        return $this->cache->get(self::key($id).'.cancelled') === true;
    }

    /**
     * The request's hand-off, or null when there is none: never asked,
     * settled, or expired.
     */
    public function find(string $id): ?Suggestion
    {
        $stored = $this->cache->get(self::key($id));

        return is_array($stored) ? Suggestion::fromArray($stored) : null;
    }

    public function forget(string $id): void
    {
        $this->cache->forget(self::key($id));
        $this->cache->forget(self::key($id).'.cancelled');
    }

    /**
     * @param  Closure(Suggestion): Suggestion  $change
     */
    private function update(string $id, Closure $change): void
    {
        $current = $this->find($id);

        // Expired, or settled already: nothing waits for it.
        if ($current !== null) {
            $this->put($change($current));
        }
    }

    private function put(Suggestion $suggestion): void
    {
        $this->cache->put(self::key($suggestion->id), $suggestion->toArray(), now()->addMinutes(self::TTL_MINUTES));
    }

    private static function key(string $id): string
    {
        return 'teme.ai.request.'.$id;
    }
}
