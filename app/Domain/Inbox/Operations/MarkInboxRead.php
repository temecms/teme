<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Operations;

use App\Domain\Inbox\Models\InboxItem;
use App\Domain\Inbox\Operations\Input\MarkInboxReadInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Marks one of a person's inbox items read, or all of them, when they open it
 * or ask (I-221). Only the person whose inbox it is may.
 */
final class MarkInboxRead extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var MarkInboxReadInput $input */
        $actorId = $input->actorId();

        // A person reads only their own inbox: nobody marks another's, not even the operator.
        $own = $actorId !== null && ($input->itemId === null
            || InboxItem::query()->whereKey($input->itemId)->where('user_id', (int) $actorId)->exists());

        if (! $own) {
            throw new AuthorizationException('Only the person whose inbox it is marks its items read.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    protected function perform(OperationInput $input): int
    {
        /** @var MarkInboxReadInput $input */
        $unread = InboxItem::query()->where('user_id', (int) $input->actorId())->whereNull('read_at');

        if ($input->itemId !== null) {
            $unread->whereKey($input->itemId);
        }

        return $unread->update(['read_at' => now()]);
    }
}
