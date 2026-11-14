<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * An Operation that announces Pinged, for the delivery tests: refused,
 * failing, running another inside it, or purging pages, as its input
 * says. Its own after-commit work and cache step are written to Steps.
 */
final class AnnouncingOperation extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var AnnouncingInput $input */
        if ($input->refuse) {
            throw new AuthorizationException;
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): string
    {
        /** @var AnnouncingInput $input */
        if ($input->build !== null) {
            $this->notifyAfterCommit(Pinged::class, $input->build);
        }

        if ($input->purges !== []) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, $input->what, $input->purges));
        }

        $what = $input->what;
        $this->notifyAfterCommit(Pinged::class, fn (): Pinged => new Pinged($what));

        if ($input->inner !== null) {
            app(self::class)->execute($input->inner);
        }

        if ($input->fail) {
            throw new RuntimeException('It failed after announcing.');
        }

        return $what;
    }

    protected function afterCommit(mixed $result): void
    {
        app(Steps::class)->add('own');
    }

    protected function invalidateCache(mixed $result): void
    {
        app(Steps::class)->add('cache');
    }
}
