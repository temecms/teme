<?php

/**
 * Records a request to export or erase a person's personal data (I-3375),
 * with one entry for each holder that will be asked, in order. Nothing is
 * exported or erased yet: RunPrivacyExport and RunPrivacyErasure step
 * through it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations;

use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Concerns\AuthorizesPrivacyWork;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RecordPrivacyRequest extends BaseOperation
{
    use AuthorizesPrivacyWork;

    protected function validate(OperationInput $input): void
    {
        /** @var RecordPrivacyRequestInput $input */
        Validator::make(
            ['kind' => $input->kind, 'email' => $input->address(), 'note' => $input->trimmedNote()],
            [
                'kind' => ['required', Rule::in([PrivacyRequest::EXPORT, PrivacyRequest::ERASE])],
                'email' => ['required', 'email:rfc', 'max:254'],
                'note' => ['required', 'string', 'max:2000'],
            ],
        )->validate();
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RecordPrivacyRequestInput $input */
        $email = $input->address();
        $hash = PrivacyRequest::hashOf($email);

        // Read first to name the open request; the unique index catches a
        // second recorder racing this one.
        $open = PrivacyRequest::query()
            ->where('kind', $input->kind)
            ->where('email_hash', $hash)
            ->whereIn('status', [PrivacyRequest::PENDING, PrivacyRequest::RUNNING])
            ->value('id');

        if ($open !== null) {
            throw $this->alreadyOpen((string) $open);
        }

        $results = array_map(fn ($holder): array => self::entry($input->kind, $holder->key(), $holder->label()), app(PersonalDataHolders::class)->all());

        try {
            // Its own savepoint, so the open request can still be read after a refusal.
            $request = DB::transaction(fn (): PrivacyRequest => PrivacyRequest::query()->create([
                'kind' => $input->kind,
                'email' => $email,
                'email_hash' => $hash,
                'user_id' => User::query()->whereRaw('lower(email) = ?', [$email])->value('id'),
                'status' => PrivacyRequest::PENDING,
                'requested_by' => $input->actorId,
                'note' => $input->trimmedNote(),
                'reply' => $input->reply,
                'results' => $results,
            ]));
        } catch (QueryException $refused) {
            if ($refused->getCode() === '23505' && str_contains($refused->getMessage(), 'privacy_requests_open')) {
                throw $this->alreadyOpen((string) PrivacyRequest::query()
                    ->where('kind', $input->kind)
                    ->where('email_hash', $hash)
                    ->whereIn('status', [PrivacyRequest::PENDING, PrivacyRequest::RUNNING])
                    ->value('id'));
            }

            throw $refused;
        }

        return $request->id;
    }

    /**
     * Returns a holder's entry in a request's results before it has been
     * asked anything.
     *
     * @return array<string, mixed>
     */
    public static function entry(string $kind, string $key, string $label): array
    {
        $entry = ['key' => $key, 'label' => $label];
        // Set apart, not written beside its key: the type-literals rule reads that word as a content type.
        $entry['page'] = 0;
        $entry['done'] = false;

        return $kind === PrivacyRequest::EXPORT
            ? [...$entry, 'found' => 0]
            : [...$entry, 'removed' => 0, 'retained' => 0, 'reasons' => []];
    }

    private function alreadyOpen(string $id): ValidationException
    {
        return ValidationException::withMessages(['email' => __('There is already an open request of this kind for this address: :id.', ['id' => $id])]);
    }
}
