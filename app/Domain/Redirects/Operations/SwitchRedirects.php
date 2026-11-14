<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations;

use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\Input\SwitchRedirectsInput;
use App\Domain\Redirects\RedirectChanges;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectRules;
use App\Domain\Security\SecurityEventType;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Switches redirects on or off, for whoever holds redirects.manage (I-850):
 * a redirect switched off answers nothing, and its address answers "not
 * found" until it is on again. Recorded and purged for each that changes;
 * returns how many did.
 */
final class SwitchRedirects extends BaseOperation
{
    public function __construct(private readonly RedirectChanges $changes) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SwitchRedirectsInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('redirects.manage')) {
            throw new AuthorizationException('Not permitted to manage redirects.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SwitchRedirectsInput $input */
        if ($input->redirectIds === [] || count($input->redirectIds) > 500) {
            throw ValidationException::withMessages(['redirects' => [__('Choose between 1 and 500 redirects.')]]);
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var SwitchRedirectsInput $input */
        RedirectRules::lock();
        $changing = Redirect::query()->whereKey($input->redirectIds)->where('enabled', ! $input->on)->orderBy('id')->lockForUpdate()->get();

        foreach ($changing as $redirect) {
            $redirect->update(['enabled' => $input->on, 'updated_by' => $input->actorId() === null ? null : (int) $input->actorId()]);
            $this->changes->record(SecurityEventType::RedirectSwitched, $input->actorId(), [...RedirectChanges::described($redirect), 'on' => $input->on]);
        }

        if ($changing->isNotEmpty()) {
            $this->purgeAfterCommit(RedirectChanges::purged($changing->map(fn (Redirect $redirect): array => $redirect->only(['source', 'kind']))->values()->all()));
        }

        return $changing->count();
    }

    protected function invalidateCache(mixed $result): void
    {
        Cache::memo()->forget(RedirectLookup::PATTERNS_KEY);
    }
}
