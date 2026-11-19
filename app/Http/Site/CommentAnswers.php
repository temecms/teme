<?php

/**
 * Each outcome of a posted comment form (I-593), as core's page about it, or,
 * for core's script, which asks for JSON, as the answer it puts in place
 * (1.8b′'s D8): what happened, the words for the form's status region, the
 * comment's place drawn by the theme, or core's form drawn again. The pages'
 * statuses (I-616); nothing keeps either.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Site\CommentViews;
use App\Domain\Site\RespondViews;
use App\Domain\Site\ViewModels\CommentTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CommentAnswers
{
    public function __construct(
        private Request $request,
        private CommentPost $post,
        private RespondViews $answers,
        private CommentViews $views,
    ) {}

    public static function for(Request $request): self
    {
        return new self($request, CommentPost::from($request), app(RespondViews::class), app(CommentViews::class));
    }

    /**
     * A comment shown at once.
     */
    public function posted(CommentTarget $target, string $commentId): Response
    {
        return $this->request->wantsJson()
            ? self::json('posted', $this->said(true), $this->place($target, $commentId), null, 200)
            : PublicPage::respond($this->request, $this->answers->posted($target, $commentId, $this->post->text), 200);
    }

    /**
     * A comment that waits for a moderator: held, judged spam, or caught by
     * the honeypot, which keeps nothing ($commentId null).
     */
    public function waiting(CommentTarget $target, ?string $commentId): Response
    {
        return $this->request->wantsJson()
            ? self::json('waiting', $this->said(false), $commentId === null ? null : $this->place($target, $commentId), null, 200)
            : PublicPage::respond($this->request, $this->answers->waiting($target, $commentId, $this->post->name, $this->post->text), 200);
    }

    /**
     * A comment that wasn't posted, and why; $target is null when the form's
     * address shows no item.
     */
    public function refused(?CommentTarget $target, string $reason, int $status): Response
    {
        return $this->request->wantsJson()
            ? self::json('refused', $reason, null, $target === null ? null : CommentFragments::form($this->answers->inThread($target, $this->post->values(), $this->parent(), ['contentId' => [$reason]])), $status)
            : PublicPage::respond($this->request, $this->answers->refused($target, $reason, $this->post->text), $status);
    }

    /**
     * The form back with what was wrong, the reader's words kept.
     *
     * @param  array<string, array<int, string>>  $errors  SubmitComment's, by field
     */
    public function invalid(CommentTarget $target, ?string $parentId, array $errors, int $status): Response
    {
        return $this->request->wantsJson()
            ? self::json('invalid', null, null, CommentFragments::form($this->answers->inThread($target, $this->post->values(), $parentId, $errors)), $status)
            : PublicPage::respond($this->request, $this->answers->invalid($target, $this->post->values(), $parentId, $errors), $status);
    }

    /**
     * The comment's place, drawn by the theme as its writer sees it, or null.
     *
     * @return array{id: string, parentId: string|null, html: string}|null
     */
    private function place(CommentTarget $target, string $commentId): ?array
    {
        $own = $this->views->justPosted($target, $commentId);

        return $own === null ? null : CommentFragments::place($own);
    }

    /**
     * The comment the reader answered, as posted, or null for none.
     */
    private function parent(): ?string
    {
        return $this->post->parent === '' ? null : $this->post->parent;
    }

    /**
     * Core's script's answer, which nothing keeps.
     *
     * @param  array{id: string, parentId: string|null, html: string}|null  $comment
     */
    private static function json(string $outcome, ?string $message, ?array $comment, ?string $form, int $status): JsonResponse
    {
        return new JsonResponse(['outcome' => $outcome, 'message' => $message, 'comment' => $comment, 'form' => $form], $status, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * What the form's status region says after a post: the design pass's
     * words, "reply" for a reply.
     */
    private function said(bool $published): string
    {
        $reply = $this->post->parent !== '';

        return match (true) {
            $published && $reply => __('Thanks. Your reply is posted.'),
            $published => __('Thanks. Your comment is posted.'),
            $reply => __('Thanks. Your reply is awaiting moderation: only you can see it until a moderator approves it.'),
            default => __('Thanks. Your comment is awaiting moderation: only you can see it until a moderator approves it.'),
        };
    }
}
