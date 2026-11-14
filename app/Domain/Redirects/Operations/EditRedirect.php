<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations;

use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\Input\EditRedirectInput;
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

/**
 * Changes a redirect's source, status, target or note, for whoever holds
 * redirects.manage (I-850), keeping its origin, its switch and its hits;
 * checked, recorded and purged as CreateRedirect is, with what it was before.
 */
final class EditRedirect extends BaseOperation
{
    public function __construct(
        private readonly RedirectRules $rules,
        private readonly RedirectChanges $changes,
        private readonly RedirectStates $states,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var EditRedirectInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('redirects.manage')) {
            throw new AuthorizationException('Not permitted to manage redirects.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var EditRedirectInput $input */
        self::proposal($input);
    }

    /**
     * @return array{id: string, state: RedirectState, label: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var EditRedirectInput $input */
        RedirectRules::lock();
        $redirect = Redirect::query()->lockForUpdate()->find($input->redirectId)
            ?? throw RedirectChanges::refused(new RedirectRefused('redirect', __('This redirect was deleted meanwhile.')));
        $proposal = self::proposal($input);

        try {
            $this->rules->check($proposal, $redirect->id);
        } catch (RedirectRefused $refused) {
            throw RedirectChanges::refused($refused);
        }

        $was = RedirectChanges::described($redirect);
        $before = $redirect->only(['source', 'kind']);
        $redirect->update([...$proposal->columns(), 'updated_by' => $input->actorId() === null ? null : (int) $input->actorId()]);

        $this->changes->record(SecurityEventType::RedirectChanged, $input->actorId(), [
            ...RedirectChanges::described($redirect),
            'was_from' => $was['from'],
            'was_to' => $was['to'],
            'was_status' => $was['status'],
        ]);
        $this->purgeAfterCommit(RedirectChanges::purged([$before, $redirect->only(['source', 'kind'])]));

        return ['id' => $redirect->id, ...$this->states->of([$redirect->id])[$redirect->id]];
    }

    protected function invalidateCache(mixed $result): void
    {
        Cache::memo()->forget(RedirectLookup::PATTERNS_KEY);
    }

    /**
     * The redirect asked for, or the form's refusal.
     */
    private static function proposal(EditRedirectInput $input): RedirectProposal
    {
        try {
            return RedirectProposal::read($input->from, $input->status, $input->to, $input->contentId, $input->mediaId, $input->note);
        } catch (RedirectRefused $refused) {
            throw RedirectChanges::refused($refused);
        }
    }
}
