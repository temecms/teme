<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * What core's comment form is drawn with (docs/studies/comments.md §3.4): where
 * it posts, the item's address it sends (never its ID, 1.7c's D11), the
 * comment it answers on a reply page, whether it asks for a website (I-596)
 * and takes signed-in people's comments only (D16), and, when it comes back
 * after a refusal, what the reader typed and what was wrong.
 */
final readonly class CommentFormView
{
    /**
     * @param  string  $action  /_teme/comments
     * @param  string  $path  the item's address, which the form posts (D9)
     * @param  bool  $askWebsite  whether the form asks for a website (I-596)
     * @param  bool  $signedInOnly  whether only signed-in people may comment (D16)
     * @param  string|null  $parentId  the comment answered, on a page of its own
     * @param  string|null  $parentName  its writer's name
     * @param  int  $keepIpDays  how many days IP addresses are kept, for the privacy line (I-270)
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
