<?php

/**
 * Core's pages about writing a comment (I-593, I-594), in the words the
 * design pass drew: one answer for each outcome of a form posted without
 * JavaScript, each keeping what the reader wrote, and the reply page
 * without it (I-594), and the form drawn again for core's script (1.8b′).
 * Spam reads as held (I-592). A lead line is HTML built here, every word
 * in it escaped.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Cards\LinkResolver;
use App\Domain\Comments\CommentBody;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Queries\CommentThreads;
use App\Domain\Contents\Models\Content;
use App\Domain\Settings\Modules;
use App\Domain\Site\ViewModels\CommentFormView;
use App\Domain\Site\ViewModels\CommentTarget;
use App\Domain\Site\ViewModels\CommentView;
use App\Domain\Site\ViewModels\RespondView;
use App\Domain\Types\ContentTypeRegistry;

final readonly class RespondViews
{
    public function __construct(
        private CommentViews $comments,
        private CommentThreads $threads,
        private ContentTypeRegistry $types,
    ) {}

    /**
     * The answer to a comment shown at once: what they wrote, and its own address.
     */
    public function posted(CommentTarget $target, string $commentId, string $text): RespondView
    {
        $heading = __('Your comment is posted');

        return new RespondView(
            title: $heading,
            heading: $heading,
            lead: [self::thanks($this->writer($commentId, '')).' '.__('It’s under :story now.', ['story' => self::story($target)])],
            context: null,
            hint: null,
            echoHtml: self::echo($text),
            keep: null,
            form: null,
            links: [
                ['href' => route('teme.comments.show', ['comment' => $commentId], false), 'text' => __('See your comment')],
                self::back($target),
            ],
        );
    }

    /**
     * The answer to a comment that waits for a moderator: held, judged spam,
     * or caught by the honeypot, which keeps nothing ($commentId null).
     */
    public function waiting(CommentTarget $target, ?string $commentId, string $name, string $text): RespondView
    {
        $heading = __('Your comment is awaiting moderation');

        return new RespondView(
            title: $heading,
            heading: $heading,
            lead: [self::thanks($commentId === null ? $name : $this->writer($commentId, $name)).' '.__('It will appear under :story once a moderator approves it. Until then it won’t show there, even to you.', ['story' => self::story($target)])],
            context: null,
            hint: null,
            echoHtml: self::echo($text),
            keep: null,
            form: null,
            links: [self::back($target)],
        );
    }

    /**
     * The answer to a comment that wasn't posted: why, and the reader's
     * words to keep a copy of; $target is null when the form's address
     * shows no item.
     */
    public function refused(?CommentTarget $target, string $reason, string $text): RespondView
    {
        $heading = __('Your comment wasn’t posted');

        return new RespondView(
            title: $heading,
            heading: $heading,
            lead: [e($reason), e(__('Here’s what you wrote, so you can keep a copy.'))],
            context: null,
            hint: null,
            echoHtml: null,
            keep: $text,
            form: null,
            links: [self::back($target)],
        );
    }

    /**
     * The form back on a page of its own, the reader's words kept and what
     * was wrong first (D14), under the comment it answers while that can
     * still be answered; otherwise as a comment of its own.
     *
     * @param  array<string, string>  $values  text, name, email and url, as typed
     * @param  array<string, array<int, string>>  $errors  SubmitComment's, by field
     */
    public function invalid(CommentTarget $target, array $values, ?string $parentId, array $errors): RespondView
    {
        $context = $this->answerable($target, $parentId);
        $heading = $context === null ? __('Leave a comment') : __('Reply to :name', ['name' => (string) $context->name]);
        $settings = DiscussionSettings::current();

        return new RespondView(
            title: __('Error: :heading', ['heading' => $heading]),
            heading: $heading,
            lead: [__('On :story', ['story' => self::story($target)])],
            context: $context,
            hint: null,
            echoHtml: null,
            keep: null,
            form: new CommentFormView(
                route('teme.comments.store', [], false),
                $target->url,
                $context?->id,
                $context?->name,
                $settings->askWebsite,
                $settings->signedInOnly,
                $settings->keepIpDays,
                standalone: true,
                values: $values,
                errors: self::errors($errors, 'page-h'),
            ),
            links: [self::back($target)],
        );
    }

    /**
     * The reply page without JavaScript (I-594, Drupal's way): the comment
     * answered above a form of its own, which posts its ID as `parent`; null
     * for a comment that takes no reply: while comments are switched off, one
     * not shown with everything above it (D3), not an approved comment of a
     * reader's, or on an item that isn't live, whose type takes none, or that
     * doesn't take new comments now.
     */
    public function reply(string $commentId): ?RespondView
    {
        if (! Modules::on(Modules::COMMENTS)) {
            return null;
        }

        $comment = $this->threads->shown($commentId);
        $item = $comment === null ? null : CommentViews::live($comment->content_id);

        if ($comment === null || $item === null || $comment->status !== CommentStatus::APPROVED || $comment->kind !== CommentKind::Comment
            || ! $this->types->has($item->type) || $this->types->get($item->type)->comments === null || ! $this->comments->open($item)) {
            return null;
        }

        // The reply page is in the item's own language (I-3360).
        ItemLanguage::use((string) $item->id);
        $target = new CommentTarget((string) $item->id, app(ArticleViews::class)->headline($item)['title'], $this->comments->itemUrl($item));
        $context = $this->comments->view($comment, $item, false, null, app(LinkResolver::class));
        $name = (string) $context->name;
        $heading = __('Reply to :name', ['name' => $name]);
        $settings = DiscussionSettings::current();

        return new RespondView(
            title: $heading,
            heading: $heading,
            lead: [__('On :story', ['story' => self::story($target)])],
            context: $context,
            // A reply at the deepest level goes beside the comment it answers (I-272, D11).
            hint: $comment->depth >= $settings->threadDepth ? __('Replies stop nesting here, so yours will appear at the end of this conversation.') : null,
            echoHtml: null,
            keep: null,
            form: new CommentFormView(route('teme.comments.store', [], false), $target->url, $comment->id, $name, $settings->askWebsite, $settings->signedInOnly, $settings->keepIpDays, standalone: true),
            links: [self::back($target)],
        );
    }

    /**
     * Core's form as a thread draws it, for core's script to swap in where the
     * form stands (1.8b′'s D8): the reader's words kept and what was wrong
     * first, under the comment it answers while that can still be answered;
     * otherwise as a comment of its own.
     *
     * @param  array<string, string>  $values  text, name, email and url, as typed
     * @param  array<string, array<int, string>>  $errors  SubmitComment's, by field
     */
    public function inThread(CommentTarget $target, array $values, ?string $parentId, array $errors): CommentFormView
    {
        $context = $this->answerable($target, $parentId);
        $settings = DiscussionSettings::current();

        return new CommentFormView(
            route('teme.comments.store', [], false),
            $target->url,
            $context?->id,
            $context?->name,
            $settings->askWebsite,
            $settings->signedInOnly,
            $settings->keepIpDays,
            values: $values,
            errors: self::errors($errors, 'respond-h'),
        );
    }

    /**
     * The comment a form answers, drawn without Reply, while it can still be
     * answered: shown, approved and a reader's, on this item; else null.
     */
    private function answerable(CommentTarget $target, ?string $parentId): ?CommentView
    {
        $answered = $parentId === null ? null : $this->threads->shown($parentId);
        $item = $answered === null ? null : Content::query()->find($target->id);

        return $answered !== null && $item !== null && $answered->content_id === $target->id
            && $answered->status === CommentStatus::APPROVED && $answered->kind === CommentKind::Comment
            ? $this->comments->view($answered, $item, false, null, app(LinkResolver::class))
            : null;
    }

    /**
     * The name a comment was kept under, a signed-in writer's their
     * account's, or the name typed when it has none.
     */
    private function writer(string $commentId, string $typed): string
    {
        $stored = Comment::query()->whereKey($commentId)->value('author_name');

        return is_string($stored) && $stored !== '' ? $stored : $typed;
    }

    /**
     * "Thanks, {first}." with the first word of the name, escaped, or
     * "Thanks." when there is none.
     */
    private static function thanks(string $name): string
    {
        $first = (preg_split('/\s+/u', trim($name)) ?: [''])[0];

        return $first === '' ? e(__('Thanks.')) : __('Thanks, :name.', ['name' => e($first)]);
    }

    /**
     * The story, as a lead line names it: its title, linked, in quotes, both escaped.
     */
    private static function story(CommentTarget $target): string
    {
        return '“<a href="'.e($target->url).'">'.e($target->title).'</a>”';
    }

    /**
     * What the reader wrote, drawn as it would show.
     */
    private static function echo(string $text): string
    {
        return CommentBody::html(CommentText::toDocument($text), app(LinkResolver::class));
    }

    /**
     * "Back to the story", at its comments, or the site's address when there is no story.
     *
     * @return array{href: string, text: string}
     */
    private static function back(?CommentTarget $target): array
    {
        return ['href' => $target === null ? '/' : $target->url.'#comments', 'text' => __('Back to the story')];
    }

    /**
     * SubmitComment's errors, one a field, in the form's order, each with the
     * element it points at: the fields', and $heading for the comment
     * answered.
     *
     * @param  array<string, array<int, string>>  $errors
     * @param  string  $heading  the element the comment answered's error points at
     *
     * @return list<array{field: string, target: string, message: string}>
     */
    private static function errors(array $errors, string $heading): array
    {
        $targets = ['text' => 'c-text', 'name' => 'c-name', 'email' => 'c-email', 'url' => 'c-url', 'parentId' => $heading];
        $order = array_flip(array_keys($targets));
        uksort($errors, fn (string $a, string $b): int => ($order[$a] ?? count($order)) <=> ($order[$b] ?? count($order)));
        $list = [];

        foreach ($errors as $field => $messages) {
            if ($messages !== []) {
                $list[] = ['field' => $field, 'target' => $targets[$field] ?? 'c-text', 'message' => (string) reset($messages)];
            }
        }

        return $list;
    }
}
