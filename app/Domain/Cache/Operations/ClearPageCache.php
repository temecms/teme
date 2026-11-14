<?php

declare(strict_types=1);

namespace App\Domain\Cache\Operations;

use App\Domain\Cache\Operations\Input\ClearPageCacheInput;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Clears the page cache by hand, for whoever holds page-cache.clear (I-467,
 * I-850): every stored page goes once the change commits, the CDN in front
 * with it, so each is drawn afresh on its next visit. Recorded in the
 * security log, so the screen can say who cleared it last. Refused while
 * the page cache is off, when there is nothing to clear.
 */
final class ClearPageCache extends BaseOperation
{
    public function __construct(private readonly SecurityLog $log) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ClearPageCacheInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('page-cache.clear')) {
            throw new AuthorizationException('Not permitted to clear the page cache.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        // Off, nothing purges, and the scheduler empties the store within a minute (docs/architecture/page-cache.md).
        if (! PageCache::enabled()) {
            throw ValidationException::withMessages(['cache' => [__('The page cache is off, so there’s nothing to clear.')]]);
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var ClearPageCacheInput $input */
        $this->log->record(SecurityEventType::PageCacheCleared, $input->actorId());
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));

        return true;
    }
}
