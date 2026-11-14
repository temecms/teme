<?php

declare(strict_types=1);

namespace App\Domain\Jobs;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The kinds of background work Teme can retry and cancel, each a JobKind its
 * own part tags under TAG. Read from the container when asked, so nothing is
 * kept between requests (I-087).
 */
final readonly class JobKinds
{
    /** The container tag a kind is registered under. */
    public const string TAG = 'teme.jobs.kinds';

    /** A kind's key: lower-case words, hyphens inside, dots between. */
    public const string KEY = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)*$/';

    public function __construct(private Container $container) {}

    /**
     * Returns the kind registered under this key, or null when none is.
     */
    public function find(string $key): ?JobKind
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Returns every registered kind, by its key. Two kinds with one key, or a
     * key out of shape, stop the request: a kind that can't be found again
     * can't be retried.
     *
     * @return array<string, JobKind>
     */
    public function all(): array
    {
        $kinds = [];

        foreach ($this->container->tagged(self::TAG) as $kind) {
            if (! $kind instanceof JobKind) {
                throw new LogicException(sprintf('%s is tagged as a job kind but isn’t one.', get_debug_type($kind)));
            }

            $key = $kind->key();

            if (strlen($key) > 64 || preg_match(self::KEY, $key) !== 1) {
                throw new LogicException(sprintf('"%s" isn’t a job kind’s key.', $key));
            }

            if (isset($kinds[$key])) {
                throw new LogicException(sprintf('Two job kinds use the key "%s".', $key));
            }

            $kinds[$key] = $kind;
        }

        return $kinds;
    }
}
