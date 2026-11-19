<?php

/**
 * Puts a result in a person's inbox, unread (I-221). Only code sends: the
 * part whose work it reports, as a person or as the operator; no route does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Inbox\Operations;

use App\Domain\Inbox\Models\InboxItem;
use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class SendToInbox extends BaseOperation
{
    /** A kind's or a job kind's key: lower-case words, hyphens inside, dots between. */
    public const string KEY = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)*$/';

    public function __construct(private Actors $actors) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SendToInboxInput $input */
        $actorId = $input->actorId();

        // A person, or the operator's own work (docs/architecture/operations.md, The operator).
        if ($actorId === null ? Operator::isPresent() : $this->actors->find($actorId) !== null) {
            return;
        }

        throw new AuthorizationException('Only a person or the operator sends to an inbox.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SendToInboxInput $input */
        Validator::make([
            'recipient' => $input->recipientId,
            'kind' => $input->kind,
            'owner' => $input->owner,
            'title' => $input->title,
            'text' => $input->text,
            'route' => $input->link?->route,
            'ability' => $input->link?->ability,
        ], [
            'recipient' => ['required', 'integer', 'exists:users,id'],
            'kind' => ['required', 'string', 'max:64', 'regex:'.self::KEY],
            'owner' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9-]*$/'],
            'title' => ['required', 'string', 'max:250'],
            'text' => ['nullable', 'string', 'max:1000'],
            // A mistyped route fails here, where it's written, not quietly when drawn (I-221).
            'route' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! Route::has($value)) {
                    $fail('No route is named '.$value.'.');
                }
            }],
            'ability' => ['nullable', 'string', 'max:64'],
        ])->validate();
    }

    protected function perform(OperationInput $input): string
    {
        /** @var SendToInboxInput $input */
        $item = InboxItem::query()->create([
            'user_id' => (int) $input->recipientId,
            'kind' => $input->kind,
            'owner' => $input->owner,
            'body' => ['title' => $input->title, 'text' => $input->text, 'link' => $input->link?->toArray()],
        ]);

        return (string) $item->id;
    }
}
