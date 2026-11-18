<?php

/**
 * What core's comment form is drawn with: where it posts, the item's address
 * it sends (never its ID), the comment it answers on a reply page, whether it
 * asks for a website and takes signed-in people's comments only, and, when it
 * comes back after a refusal, what the reader typed and what was wrong.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * What core's comment form is drawn with: where
 * it posts, the item's address it sends (never its ID), the
 * comment it answers on a reply page, whether it asks for a website
 * and takes signed-in people's comments only, and, when it comes back
 * after a refusal, what the reader typed and what was wrong.
 */
final readonly class CommentFormView
{
    /**
     * @param  string  $action  /_teme/comments
     * @param  string  $path  the item's address, which the form posts
     * @param  bool  $askWebsite  whether the form asks for a website
     * @param  bool  $signedInOnly  whether only signed-in people may comment
     * @param  string|null  $parentId  the comment answered, on a page of its own
     * @param  string|null  $parentName  its writer's name
     * @param  int  $keepIpDays  how many days IP addresses are kept, for the privacy line
     * @param  bool  $standalone  on a page of its own, headed by the page's h1
     * @param  array<string, string>  $values  text, name, email, url: what the reader typed
     * @param  list<array{field: string, target: string, message: string}>  $errors  each with the field's element ID
     */
    public function __construct(
        public string $action,
        public string $path,
        public ?string $parentId,
        public ?string $parentName,
        public bool $askWebsite,
        public bool $signedInOnly,
        public int $keepIpDays,
        public bool $standalone = false,
        public array $values = [],
        public array $errors = [],
    ) {}
}
