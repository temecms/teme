<?php

/**
 * Mail can fail and registration is off; an operator with a shell must never
 * be locked out (study G, S12). The link is Laravel's ordinary reset link,
 * valid for 60 minutes, used once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Operations\Input\IssuePasswordResetLinkInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class IssuePasswordResetLink extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator can issue reset links by hand.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var IssuePasswordResetLinkInput $input */
        if (! User::query()->where('email', Str::lower(trim($input->email)))->exists()) {
            throw ValidationException::withMessages(['email' => __('No account uses that address.')]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var IssuePasswordResetLinkInput $input */
        $user = User::query()->where('email', Str::lower(trim($input->email)))->firstOrFail();

        app(SecurityLog::class)->record(SecurityEventType::PasswordResetLinkIssued, $user->id, context: ['by' => 'operator']);

        return Password::broker()->createToken($user);
    }
}
