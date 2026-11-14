<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations\Concerns;

use App\Domain\Cache\PageTags;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\SiteCode\SiteCode;
use App\Domain\SiteCode\SiteCodeEntries;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * What every site code Operation shares (I-288): who may change site code,
 * the host's switch, the password asked again, the security log's before
 * and after, and what the page cache learns.
 */
trait ChangesSiteCode
{
    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function authorize(OperationInput $input): void
    {
        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());

        if ($actor === null || ! $actor->can(SiteCode::ABILITY)) {
            throw new AuthorizationException('Not permitted to change site code.');
        }

        // Authorization, as WordPress's DISALLOW_UNFILTERED_HTML takes the
        // capability from everyone, so nobody confirms a password in vain.
        if (SiteCode::disallowed()) {
            throw new AuthorizationException('The host has switched site code off, so it can’t be changed here.');
        }
    }

    /**
     * Run by BaseOperation after commit, before the page cache's purge, so a
     * page drawn again reads the entries as they now are.
     */
    protected function invalidateCache(mixed $result): void
    {
        Cache::memo()->forget(SiteCodeEntries::CACHE_KEY);
    }

    /**
     * The change in the security log, with the entry before and after it,
     * flat: the log's context holds plain values only.
     *
     * @param  array{name: string, placement: string, on: bool, code: string, origins: string}|null  $before
     * @param  array{name: string, placement: string, on: bool, code: string, origins: string}|null  $after
     */
    private function logChange(SecurityEventType $type, OperationInput $input, string $entryId, ?array $before, ?array $after): void
    {
        $context = ['entry' => $entryId];

        foreach (['before' => $before, 'after' => $after] as $when => $entry) {
            foreach (['name', 'placement', 'on', 'code', 'origins'] as $field) {
                $context["{$when}_{$field}"] = $entry[$field] ?? null;
            }
        }

        app(SecurityLog::class)->record($type, $input->actorId(), context: $context);
    }

    /**
     * Every stored page goes, after commit, when pages drew the entry before
     * the change or draw it after; an entry off throughout changes no page.
     *
     * @param  array{name: string, placement: string, on: bool, code: string, origins: string}|null  $before
     * @param  array{name: string, placement: string, on: bool, code: string, origins: string}|null  $after
     */
    private function purgeIfDrawn(?array $before, ?array $after): void
    {
        if (($before['on'] ?? false) || ($after['on'] ?? false)) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));
        }
    }
}
