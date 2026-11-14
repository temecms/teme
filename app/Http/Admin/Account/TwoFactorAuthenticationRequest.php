<?php

declare(strict_types=1);

namespace App\Http\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Fortify\InteractsWithTwoFactorState;

/**
 * The request behind the Security page. It carries Fortify's check that resets
 * a half-finished two-factor setup when the page is opened.
 */
final class TwoFactorAuthenticationRequest extends FormRequest
{
    use InteractsWithTwoFactorState;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
