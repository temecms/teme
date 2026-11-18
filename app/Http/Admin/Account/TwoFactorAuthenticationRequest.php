<?php

/**
 * The request behind the Security page. It carries Fortify's check that resets
 * a half-finished two-factor setup when the page is opened.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Fortify\InteractsWithTwoFactorState;

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
