<?php

/**
 * `<x-teme::comment-form-fields :form="$form" />`: the hidden fields, honeypot and thread script every comment form
 * carries (issue 788). A theme's override of the comment form includes it inside its form, and core's form does too.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Site\ViewModels\CommentFormView;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The fields a comment form must carry for its submission to be read: the item's address, the reply's parent, the
 * tokens and the honeypot, with the script that shows them. It draws only inside a form that posts to the form's action.
 */
final class CommentFormFields extends Component
{
    /**
     * @param  CommentFormView  $form  the form the fields belong to: where it posts, the item's address and the reply's parent
     */
    public function __construct(public CommentFormView $form) {}

    public function render(): View
    {
        return view('teme::components.comment-form-fields');
    }
}
