<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\Input\RecordRemoteActorInput;
use App\Domain\Fediverse\Remote\RemoteActorDocument;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Keeps a remote account as its own document describes it, adding it or
 * bringing it up to date, and counting it back if it was gone. Run from
 * the inbox's job for the verified sender's own document, or by the
 * operator; returns the account.
 */
final class RecordRemoteActor extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var RecordRemoteActorInput $input */
        if ($input->actorId() !== null) {
            throw new AuthorizationException('Only a remote account records itself.');
        }

        if ($input->sender !== null && $input->sender->actorUri === ($input->document['id'] ?? null)) {
            return;
        }

        if ($input->sender === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException('Only a remote account records itself.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordRemoteActorInput $input */
        try {
            RemoteActorDocument::attributes($input->document, $input->sender?->keyId);
        } catch (InvalidArgumentException $refusal) {
            throw ValidationException::withMessages(['document' => $refusal->getMessage()]);
        }
    }

    protected function perform(OperationInput $input): RemoteActor
    {
        /** @var RecordRemoteActorInput $input */
        $attributes = RemoteActorDocument::attributes($input->document, $input->sender?->keyId);
        $remote = RemoteActor::query()->where('uri', $attributes['uri'])->lockForUpdate()->first() ?? new RemoteActor;

        $remote->fill([...$attributes, 'fetched_at' => now(), 'gone_at' => null])->save();

        return $remote;
    }
}
