<?php

/**
 * Core's page about writing a comment, as the theme's `respond` template sees
 * it, as `$page`: the reply page, or the answer to a comment form posted
 * without JavaScript, in core's words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * Core's page about writing a comment, as the theme's `respond` template sees
 * it, as `$page`: the reply page, or the answer to a comment
 * form posted without JavaScript, in core's words. Never stored or indexed.
 */
final readonly class RespondView
{
    /**
     * @param  string  $title  the document's title
     * @param  string  $heading  the page's h1
     * @param  list<string>  $lead  its opening lines: HTML core built, every word escaped, which a theme prints as it is
     * @param  CommentView|null  $context  the comment answered, drawn without Reply
     * @param  string|null  $hint  a line under the heading, such as where a reply at the deepest level goes
     * @param  string|null  $echoHtml  what the reader wrote, drawn as it shows under the story (core escaped it)
     * @param  string|null  $keep  the reader's words when they weren't posted, to keep a copy of
     * @param  CommentFormView|null  $form  the form: on the reply page, and back with its errors
     * @param  list<array{href: string, text: string}>  $links  where to go next
     */
    public function __construct(
        public string $title,
        public string $heading,
        public array $lead,
        public ?CommentView $context,
        public ?string $hint,
        public ?string $echoHtml,
        public ?string $keep,
        public ?CommentFormView $form,
        public array $links,
    ) {}
}
