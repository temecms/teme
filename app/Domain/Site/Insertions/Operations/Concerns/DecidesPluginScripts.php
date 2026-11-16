<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations\Concerns;

use App\Domain\Cache\PageTags;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Site\Insertions\Scripts\ApprovedScripts;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\SiteCode\Operations\Concerns\ChangesSiteCode;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * What approving, refusing and revoking a plugin's script share with site
 * code's own changes (I-288): who may decide, the host's switch, the
 * password asked again, the security log's before and after, and the pages
 * that drew insertions purged after commit.
 */
trait DecidesPluginScripts
{
    use ChangesSiteCode;

    /**
     * Run by BaseOperation after commit, before the page cache's purge, so a
     * page drawn again reads the approvals as they now are.
     */
    protected function invalidateCache(mixed $result): void
    {
        ApprovedScripts::forget();
    }

    /** The script, under its lock, or a refusal when it is gone. */
    private function lockedScript(string $id): PluginScript
    {
        return PluginScript::query()->lockForUpdate()->find($id)
            ?? throw ValidationException::withMessages(['script' => __('This script was decided on or revoked while you had it open.')]);
    }

    /**
     * The decision in the security log, with the script before and after
     * it, flat, and the pages that drew insertions purged after commit.
     *
     * @param  array{plugin: string, name: string, state: string, hash: string, script: string}  $before
     * @param  array{plugin: string, name: string, state: string, hash: string, script: string}|null  $after
     */
    private function decided(SecurityEventType $type, OperationInput $input, string $scriptId, array $before, ?array $after): void
    {
        $context = ['script' => $scriptId, 'plugin' => $before['plugin'], 'name' => $before['name'], 'hash' => $before['hash']];

        foreach (['before' => $before, 'after' => $after] as $when => $script) {
            $context["{$when}_state"] = $script['state'] ?? null;
            $context["{$when}_script"] = $script['script'] ?? null;
        }

        app(SecurityLog::class)->record($type, $input->actorId(), context: $context);
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SETTINGS, 'plugin-scripts', [PageTags::INSERTIONS, PageTags::PAGE_INSERTIONS]));
    }
}
