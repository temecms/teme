<?php

/**
 * Adds a redirect, for whoever holds redirects.manage (I-850, I-694): from
 * an address on this site to an address, another site's address, an item or
 * a media file, or answering 410 or 451. A plugin granted redirects adds
 * one as the site, marked with its code. Refused when its source is taken or
 * it would make a loop or a chain (I-859); recorded in the security log; the
 * stored "not found" pages it replaces purged after commit. Returns its ID
 * and its state, which may be waiting (I-858).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations;

use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\Input\CreateRedirectInput;
use App\Domain\Redirects\PluginRedirectGrant;
use App\Domain\Redirects\Queries\RedirectStates;
use App\Domain\Redirects\RedirectChanges;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectProposal;
use App\Domain\Redirects\RedirectRefused;
use App\Domain\Redirects\RedirectRules;
use App\Domain\Redirects\RedirectState;
use App\Domain\Security\SecurityEventType;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Teme\Contracts\Operations\OperationInput;

final class CreateRedirect extends BaseOperation
{
    public function __construct(
        private readonly RedirectRules $rules,
        private readonly RedirectChanges $changes,
        private readonly RedirectStates $states,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var CreateRedirectInput $input */
        if ($input->plugin !== null) {
            PluginRedirectGrant::require($input->plugin);

            return;
        }

        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('redirects.manage')) {
            throw new AuthorizationException('Not permitted to manage redirects.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreateRedirectInput $input */
        self::proposal($input);
    }

    /**
     * @return array{id: string, state: RedirectState, label: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var CreateRedirectInput $input */
        // Before anything is read, so two saves at once can't together make a chain (I-859).
        RedirectRules::lock();
        $proposal = self::proposal($input);

        try {
            $this->rules->check($proposal, null);
        } catch (RedirectRefused $refused) {
            throw RedirectChanges::refused($refused);
        }

        $actor = $input->actorId() === null ? null : (int) $input->actorId();
        $redirect = Redirect::query()->create([...$proposal->columns(), 'origin' => $input->plugin === null ? 'manual' : 'plugin', 'plugin' => $input->plugin, 'enabled' => true, 'created_by' => $actor, 'updated_by' => $actor]);

        $this->changes->record(SecurityEventType::RedirectCreated, $input->actorId(), [...RedirectChanges::described($redirect), ...($input->plugin === null ? [] : ['plugin' => $input->plugin])]);
        $this->purgeAfterCommit(RedirectChanges::purged([$redirect->only(['source', 'kind'])]));

        return ['id' => $redirect->id, ...$this->states->of([$redirect->id])[$redirect->id]];
    }

    protected function invalidateCache(mixed $result): void
    {
        Cache::memo()->forget(RedirectLookup::PATTERNS_KEY);
    }

    /**
     * The redirect asked for, or the form's refusal.
     */
    private static function proposal(CreateRedirectInput $input): RedirectProposal
    {
        try {
            return RedirectProposal::read($input->from, $input->status, $input->to, $input->contentId, $input->mediaId, $input->note);
        } catch (RedirectRefused $refused) {
            throw RedirectChanges::refused($refused);
        }
    }
}
