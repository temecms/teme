<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Site\ViewModels\CommentFormView;
use App\Domain\Site\ViewModels\OwnComment;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;

/**
 * Pieces of a public page core's script puts in place (1.8b′'s D7, D8): a
 * comment drawn by the theme's own partial, or core's plain one for a theme
 * without it, and core's form drawn as a thread draws it.
 */
final class CommentFragments
{
    /**
     * @return array{id: string, parentId: string|null, html: string}
     */
    public static function place(OwnComment $own): array
    {
        // Reading the active theme points theme:: at it, or at core's fallback.
        app(ActiveTheme::class)->get();
        $view = View::exists('theme::partials.comment') ? 'theme::partials.comment' : 'teme::fallback.partials.comment';

        return ['id' => $own->id, 'parentId' => $own->parentId, 'html' => view($view)->with('comment', $own->place)->render()];
    }

    /**
     * Core's form as a thread draws it, for the script to swap in where the form stands.
     */
    public static function form(CommentFormView $form): string
    {
        return Blade::render('<x-teme::comment-form :form="$form" />', ['form' => $form]);
    }
}
