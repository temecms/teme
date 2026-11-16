<?php

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Cache\SignedInMarker;
use App\Domain\Site\ViewModels\CommentFormView;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::comment-form>`: core's comment form, which a theme places and styles. Where only signed-in people
 * may comment, a browser that carries no sign-in marker, whose page may be
 * stored and shared, gets a line in its place.
 */
final class CommentForm extends Component
{
    /** Whether a line stands in the form's place: signed-in people only, and this browser carries no sign-in marker. */
    public bool $closedToGuests;

    /** The level of the form's own heading: 1 on a page of its own, where the page's h1 heads it, else 3. */
    public int $level;

    /**
     * @param  CommentFormView  $form  what the form is drawn with
     */
    public function __construct(public CommentFormView $form)
    {
        $this->closedToGuests = $form->signedInOnly && ! SignedInMarker::present(request());
        $this->level = $form->standalone ? 1 : 3;
    }

    public function render(): View
    {
        return view('teme::components.comment-form');
    }
}
