<?php

/**
 * `<x-teme::password-form>`: core's form for an item protected by a password,
 * which a theme places and styles.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Site\ViewModels\ProtectedView;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::password-form>`: core's form for an item protected by a password,
 * which a theme places and styles.
 */
final class PasswordForm extends Component
{
    /**
     * @param  ProtectedView  $page  the protected item's page
     */
    public function __construct(public ProtectedView $page) {}

    public function render(): View
    {
        return view('teme::components.password-form');
    }
}
