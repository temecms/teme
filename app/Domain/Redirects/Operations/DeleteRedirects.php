<?php

/**
 * Deletes redirects, for whoever holds redirects.manage (I-850): their
 * addresses answer "not found" again and reach the 404 log. Recorded and
 * purged for each; returns how many went.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations;

use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\Input\DeleteRedirectsInput;
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

final class DeleteRedirects extends BaseOperation
{
    public function __construct(private readonly RedirectChanges $changes) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var DeleteRedirectsInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('redirects.manage')) {
            throw new AuthorizationException('Not permitted to manage redirects.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DeleteRedirectsInput $input */
        if ($input->redirectIds === [] || count($input->redirectIds) > 500) {
            throw ValidationException::withMessages(['redirects' => [__('Choose between 1 and 500 redirects.')]]);
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var DeleteRedirectsInput $input */
        RedirectRules::lock();
        $going = Redirect::query()->whereKey($input->redirectIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($going as $redirect) {
            $this->changes->record(SecurityEventType::RedirectDeleted, $input->actorId(), RedirectChanges::described($redirect));
            $redirect->delete();
        }

        if ($going->isNotEmpty()) {
            $this->purgeAfterCommit(RedirectChanges::purged($going->map(fn (Redirect $redirect): array => $redirect->only(['source', 'kind']))->values()->all()));
        }

        return $going->count();
    }

    protected function invalidateCache(mixed $result): void
    {
        Cache::memo()->forget(RedirectLookup::PATTERNS_KEY);
    }
}
