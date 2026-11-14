<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Cache\SignedInMarker;
use App\Domain\Comments\Operations\Input\SubmitCommentInput;
use App\Domain\Comments\Operations\SubmitComment;
use App\Domain\Site\CommentViews;
use App\Domain\Site\RespondViews;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Core's comment addresses: posting the form (I-593), a comment's own
 * address (I-272), and the reply page without JavaScript (I-594). It reads
 * through CommentViews and RespondViews and writes through SubmitComment,
 * never a model.
 */
final class CommentController extends Controller
{
    /**
     * The comment form posted (I-593), past GuardCommentPost (D7): the item
     * its address names, the honeypot, then SubmitComment, answered with one
     * page for each outcome, never stored, the reader's words kept, or in JSON
     * when core's script asks (1.8b′). Spam and a filled honeypot read as held
     * (I-592).
     */
    public function store(Request $request, SubmitComment $submit, CommentViews $views): Response
    {
        $post = CommentPost::from($request);
        $answer = CommentAnswers::for($request);
        $target = $views->target($post->path);

        if ($target === null) {
            return $answer->refused(null, __('This page takes no comments.'), 422);
        }

        // A bot's: answered as held, and nothing reaches SubmitComment or its rate limit (D8, I-578).
        if ($post->honeypot !== '') {
            return $answer->waiting($target, null);
        }

        // Signed in only while the browser carries the marker, whose session the guard read.
        $actor = SignedInMarker::present($request) ? $request->user()?->getAuthIdentifier() : null;
        $parentId = $post->parent === '' ? null : $post->parent;

        try {
            $submitted = $submit->execute(new SubmitCommentInput(
                contentId: $target->id,
                text: $post->text,
                parentId: $parentId,
                name: $post->name,
                email: $post->email,
                url: $post->url,
                commenterToken: $post->token === '' ? null : $post->token,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
                actorId: $actor === null ? null : (string) $actor,
            ));
        } catch (AuthorizationException $refused) {
            return $answer->refused($target, $refused->getMessage(), 403);
        } catch (ValidationException $invalid) {
            $errors = $invalid->errors();

            // Switched off, closed, or taking none while the reader wrote: their words kept (I-593).
            if (isset($errors['contentId'][0])) {
                return $answer->refused($target, $errors['contentId'][0], 422);
            }

            return $answer->invalid($target, $parentId, $errors, $invalid->status === 429 ? 429 : 422);
        }

        return $submitted->published()
            ? $answer->posted($target, $submitted->commentId)
            : $answer->waiting($target, $submitted->commentId);
    }

    /**
     * A comment's own address (I-272): a 302 to the page its conversation is
     * on, at its anchor, since that page moves when an earlier conversation is
     * taken down; the not-found page for a comment no reader sees.
     */
    public function show(Request $request, string $comment, CommentViews $views): Response
    {
        PublicPage::start($request);
        $target = $views->permalink($comment);

        // Not redirect(): Laravel's URL generator trims the trailing slash.
        return $target === null
            ? PublicPage::notFound($request)
            : new RedirectResponse(rtrim(url('/'), '/').$target, 302, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * The reply page without JavaScript (I-594): the comment answered above a
     * form of its own, never stored or indexed; the not-found page for a
     * comment that takes no reply.
     */
    public function reply(Request $request, string $comment, RespondViews $answers): Response
    {
        PublicPage::start($request);
        $view = $answers->reply($comment);

        return $view === null ? PublicPage::notFound($request) : PublicPage::respond($request, $view, 200);
    }
}
