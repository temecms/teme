<?php

/**
 * Asks a task's model, and its fallback once if the first can't be reached,
 * is busy or takes too long, all within one deadline (I-1321): the call core's
 * assistant and the plugins' SiteAi share. The service's own words never leave
 * it: any other error becomes unavailable, reported by its class alone.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Throwable;

final readonly class ModelCall
{
    /** The fallback isn't asked with less time than this left (I-1321). */
    public const int FALLBACK_FLOOR_SECONDS = 10;

    public function __construct(private AiProviders $providers) {}

    /**
     * Asks the choice's model, then its fallback once with exactly the time
     * left under the deadline, counted from $since (now when null). The first
     * model waits $firstSeconds, the whole deadline when null. $stillWanted is
     * asked before the fallback; false keeps the first failure.
     *
     * @param  Closure(ModelRef, int): AiRequest  $request  the request to send a model, given its time
     * @param  (Closure(): bool)|null  $stillWanted
     */
    public function ask(ModelChoice $choice, Closure $request, int $deadlineSeconds, ?int $firstSeconds = null, ?Closure $stillWanted = null, ?CarbonImmutable $since = null): ModelCallResult
    {
        $started = $since ?? CarbonImmutable::now();
        $sentTo = [];
        $ref = $choice->primary;

        try {
            try {
                $result = $this->one($ref, $request($ref, $firstSeconds ?? $deadlineSeconds), $sentTo);
            } catch (AiFailure $failure) {
                $left = $deadlineSeconds - (int) ceil($this->elapsedMs($started) / 1000);
                $fallback = $choice->fallback;

                if (! $failure->type->fallsBack() || $fallback === null || $left < self::FALLBACK_FLOOR_SECONDS
                    || ! ($this->providers->find($fallback->provider)?->ready() ?? false) || ($stillWanted !== null && ! $stillWanted())) {
                    throw $failure;
                }

                // Exactly the time that is left under the deadline (I-1321).
                $ref = $fallback;
                $result = $this->one($ref, $request($ref, $left), $sentTo);
            }
        } catch (AiFailure $failure) {
            // A failure the service billed carries its tokens, and they count (I-1322).
            $usage = $failure->usage ?? new AiUsage;
            $cost = $failure->usage === null ? null : $this->providers->cost($ref, $failure->usage);

            return new ModelCallResult($ref, null, $failure->type, $usage, $cost, $ref->model, $this->elapsedMs($started), $sentTo);
        }

        return new ModelCallResult($ref, $result, null, $result->usage, $this->providers->cost($ref, $result->usage), $result->model, $this->elapsedMs($started), $sentTo);
    }

    /**
     * One call to one model, its provider added to who the text was sent to.
     *
     * @param  list<string>  $sentTo
     *
     * @throws AiFailure
     */
    private function one(ModelRef $ref, AiRequest $request, array &$sentTo): AiResult
    {
        $provider = $this->providers->find($ref->provider) ?? throw AiFailure::unavailable();

        if (! in_array($provider->label(), $sentTo, true)) {
            $sentTo[] = $provider->label();
        }

        try {
            return $provider->complete($request);
        } catch (AiFailure $failure) {
            throw $failure;
        } catch (Throwable $unexpected) {
            // Its message may quote the prompt (I-320), so only its class is reported.
            report(new RuntimeException('An AI provider threw '.$unexpected::class.'; its message isn\'t kept, since it may quote the prompt.'));

            throw AiFailure::unavailable();
        }
    }

    private function elapsedMs(CarbonImmutable $started): int
    {
        return (int) abs($started->diffInMilliseconds(CarbonImmutable::now()));
    }
}
