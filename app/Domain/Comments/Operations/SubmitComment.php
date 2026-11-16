<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Comments\CommentDocument;
use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommenterToken;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentPlace;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\CommentThrottle;
use App\Domain\Comments\Discussion;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\Input\SubmitCommentInput;
use App\Domain\Comments\ReturningCommenter;
use App\Domain\Comments\SubmittedComment;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Visibility;
use App\Domain\Hooks\Payloads;
use App\Domain\Settings\Modules;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\CommentModerator;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Comments\ModerationVerdict;
use Teme\Contracts\Operations\OperationInput;
use UnexpectedValueException;

/**
 * Takes a comment on a published item (docs/studies/comments.md §3.4,
 * I-275): the cheapest checks first, then a moderator's judgement, then the
 * comment and its item's counts in one transaction. Whoever looks after the
 * item's comments is approved at once, past every list and check (I-483); a
 * guest gives a name and an email address (I-268).
 */
final class SubmitComment extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SubmitCommentInput $input */
        if ($input->actorId() === null) {
            if (DiscussionSettings::current()->signedInOnly) {
                throw new AuthorizationException('Sign in to comment here.');
            }

            return;
        }

        $actor = User::query()->find($input->actorId());

        if ($actor === null || $actor->suspended_at !== null) {
            throw new AuthorizationException('Not permitted to comment.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SubmitCommentInput $input */
        if (! Modules::on(Modules::COMMENTS)) {
            throw ValidationException::withMessages(['contentId' => __('Comments are switched off.')]);
        }

        $actor = $input->actorId() === null ? null : User::query()->findOrFail($input->actorId());
        self::checkFields($input, $actor === null);

        $item = self::openItem($input->contentId);
        [$name, $email] = self::writer($input, $actor);

        // Counted in the cache store, before the database is asked anything
        // more; whoever looks after the item's comments is never held back (D13).
        if ($actor === null || ! CommentModeration::allows($actor, $item)) {
            self::throttle($input->ip, $email);
        }

        $document = CommentText::toDocument(trim($input->text));
        CommentDocument::validate($document);

        if ($input->parentId !== null) {
            self::answered($input->parentId, $item, lock: false);
        }

        if ($actor === null) {
            self::refuseTaken($name, $email);
        }

        // The same words on this item from this email address: a form sent twice.
        if (Comment::query()->where('author_email', $email)->where('content_id', $item->id)->whereRaw('body = ?::jsonb', [json_encode($document, JSON_THROW_ON_ERROR)])->exists()) {
            throw ValidationException::withMessages(['text' => __('You’ve already said that here.')]);
        }
    }

    protected function perform(OperationInput $input): SubmittedComment
    {
        /** @var SubmitCommentInput $input */
        $item = Content::query()->findOrFail($input->contentId);
        $actor = $input->actorId() === null ? null : User::query()->findOrFail($input->actorId());
        [$name, $email] = self::writer($input, $actor);
        [$parentId, $parentPath, $depth] = self::place($input->parentId, $item, DiscussionSettings::current()->threadDepth);
        $text = trim($input->text);
        $document = CommentText::toDocument($text);
        $tokenHash = $input->commenterToken === null ? null : CommenterToken::hash($input->commenterToken);
        $url = self::website($input->url);

        if ($actor !== null && CommentModeration::allows($actor, $item)) {
            $by = 'Teme';
            $verdict = new ModerationVerdict(ModerationVerdict::APPROVED, 'Approved at once: whoever looks after this item’s comments.', ['rule' => 'looks-after']);
        } else {
            // In the transaction: core's moderator is local (D14).
            $moderator = app(CommentModerator::class);
            $by = $moderator->name();
            $verdict = self::known($by, $moderator->judge(new CommentCandidate(
                contentId: $item->id,
                parentId: $parentId,
                text: $text,
                links: CommentText::links($document),
                authorName: $name,
                authorEmail: $email,
                authorUrl: $url,
                authorUserId: $actor === null ? null : (string) $actor->id,
                ip: $input->ip,
                userAgent: $input->userAgent,
                returning: ReturningCommenter::isReturning($email, $tokenHash, $actor?->id),
                itemUrl: Addresses::urlFor($item),
            )));
        }

        // From the clock, so the thread's path order is the order of writing (D16).
        $id = strtolower((string) Str::ulid(now()));

        $comment = Comment::query()->create([
            'id' => $id,
            'content_id' => $item->id,
            'parent_id' => $parentId,
            'path' => $parentPath.$id.'/',
            'depth' => $depth,
            'kind' => CommentKind::Comment,
            'status' => $verdict->status,
            'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
            'author_name' => $name,
            'author_email' => $email,
            'author_url' => $url,
            'author_user_id' => $actor?->id,
            'body' => $document,
            'commenter_token_hash' => $tokenHash,
            'ip' => $input->ip,
            'user_agent' => $input->userAgent === null ? null : mb_substr($input->userAgent, 0, 255),
            'moderation' => ['by' => $by, 'status' => $verdict->status, 'reason' => $verdict->reason, 'signals' => (object) $verdict->signals],
        ]);

        CommentStats::apply($comment, null, $comment->status);

        // A comment shown at once sets its item's thread pages aside (D5, I-276).
        if ($comment->status === CommentStatus::APPROVED) {
            $this->purgeAfterCommit(CommentPurges::arrived($item->id));
        }

        // The emails (1.8d's D7, D8): a held comment to whoever looks after
        // the item, an approved one to its author; both after commit.
        if ($comment->status === CommentStatus::PENDING) {
            CommentEmails::waiting($comment, $input->actorId());
        } elseif ($comment->status === CommentStatus::APPROVED) {
            CommentEmails::approved($comment, $input->actorId());
        }

        $status = (string) $comment->status;
        $actorId = $input->actorId();
        $this->notifyAfterCommit(CommentStatusChanged::class, fn (): CommentStatusChanged => new CommentStatusChanged((string) $id, Payloads::item($item), null, $status, $actorId));

        return new SubmittedComment($comment->id, $comment->status);
    }

    private static function checkFields(SubmitCommentInput $input, bool $guest): void
    {
        $text = trim($input->text);

        Validator::make(
            [
                'text' => $text,
                'name' => $input->name === null ? null : trim($input->name),
                'email' => $input->email === null ? null : trim($input->email),
                'url' => self::website($input->url),
                'parentId' => $input->parentId,
                'commenterToken' => $input->commenterToken,
                'ip' => $input->ip,
            ],
            [
                'text' => ['required', 'string', 'max:5000'],
                'name' => $guest ? ['required', 'string', 'max:100'] : ['nullable', 'string'],
                'email' => $guest ? ['required', 'string', 'email:rfc', 'max:254'] : ['nullable', 'string'],
                'url' => ['nullable', 'string', 'max:200', 'url:http,https'],
                'parentId' => ['nullable', 'string', 'size:26'],
                'commenterToken' => ['nullable', 'string', 'regex:'.CommenterToken::PATTERN],
                'ip' => ['nullable', 'ip'],
            ],
            // The form's own words, as the design pass drew them (D14).
            [
                'text.required' => __('Write your comment.'),
                'text.max' => __('Shorten your comment to 5,000 characters or fewer. It has :count.', ['count' => Number::format(mb_strlen($text), locale: app()->getLocale())]),
                'name.required' => __('Enter your name.'),
                'name.max' => __('Enter a name of 100 characters or fewer.'),
                'email.required' => __('Enter your email address.'),
                'email.email' => __('Enter an email address like name@example.com.'),
                'email.max' => __('Enter an email address like name@example.com.'),
                'url.url' => __('Enter a web address that starts with https://, or leave this empty.'),
                'url.max' => __('Enter a web address that starts with https://, or leave this empty.'),
            ],
        )->validate();
    }

    /**
     * The item, when it takes comments now: published and live, dated now or
     * earlier, of a type that takes comments, open, and within the days the
     * settings allow after its date (I-274).
     */
    private static function openItem(string $contentId): Content
    {
        $item = Content::query()->find($contentId);
        $types = app(ContentTypeRegistry::class);
        // Parsed once, as the house does (B-350): Larastan reads published_at as a string.
        $published = $item?->published_at === null ? null : CarbonImmutable::parse((string) $item->published_at);

        // Only public items go beyond the site's own pages (I-1700).
        if ($item === null || $item->status !== 'published' || $item->visibility !== Visibility::Public || $published === null || $published->isFuture()
            || ! $types->has($item->type) || $types->get($item->type)->comments === null) {
            throw ValidationException::withMessages(['contentId' => __('This page takes no comments.')]);
        }

        $days = DiscussionSettings::current()->closeAfterDays;

        if ($item->comment_status !== Discussion::Open || ($days > 0 && $published->lt(now()->subDays($days)))) {
            throw ValidationException::withMessages(['contentId' => __('Comments here are closed.')]);
        }

        return $item;
    }

    /**
     * The writer's name and lower-cased email address: their account's when
     * signed in, whatever the input says, and a guest's as given, trimmed.
     *
     * @return array{0: string, 1: string}
     */
    private static function writer(SubmitCommentInput $input, ?User $actor): array
    {
        if ($actor !== null) {
            return [(string) $actor->name, mb_strtolower((string) $actor->email)];
        }

        return [trim((string) $input->name), mb_strtolower(trim((string) $input->email))];
    }

    /**
     * The website a guest gave, as it is judged and stored (D14): trimmed,
     * with https:// before one typed without a scheme (a colon before a digit
     * is a port, not a scheme), or null for none; and null whenever the site
     * doesn't ask for one (I-596), whatever a form sends.
     */
    private static function website(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || ! DiscussionSettings::current()->askWebsite) {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:(?!\d)/i', $url) === 1 ? $url : 'https://'.$url;
    }

    private static function throttle(?string $ip, string $email): void
    {
        $keys = CommentThrottle::keys($ip, $email);

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, CommentThrottle::MAX)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

                throw ValidationException::withMessages([
                    'text' => trans_choice('{1} You’ve sent several comments in a short time. Try again in a minute.|[2,*] You’ve sent several comments in a short time. Try again in :minutes minutes.', $minutes, ['minutes' => $minutes]),
                ])->status(429);
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, CommentThrottle::DECAY);
        }
    }

    /**
     * The comment a reply answers: there, on this item, and approved; locked
     * when the reply is about to be written, so it can't be taken down
     * meanwhile.
     */
    private static function answered(string $id, Content $item, bool $lock): Comment
    {
        $query = Comment::query();
        $comment = ($lock ? $query->lockForUpdate() : $query)->find($id);

        if ($comment === null || $comment->content_id !== $item->id || $comment->status !== CommentStatus::APPROVED) {
            throw ValidationException::withMessages(['parentId' => __('The comment you’re replying to isn’t there to reply to.')]);
        }

        return $comment;
    }

    /**
     * Where a reply goes (I-272): under the comment it answers, one level
     * deeper; beside it, under its ancestor, when it sits at the deepest level
     * the settings allow or below it. Returns the parent's ID and path and the
     * new comment's depth; the ancestors are read from the path.
     *
     * @return array{0: string|null, 1: string, 2: int}
     */
    private static function place(?string $answering, Content $item, int $deepest): array
    {
        if ($answering === null) {
            return [null, '', 1];
        }

        return CommentPlace::under(self::answered($answering, $item, lock: true), $deepest);
    }

    /**
     * A guest may not write as someone with an account here (Drupal).
     */
    private static function refuseTaken(string $name, string $email): void
    {
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => __('This email address belongs to someone with an account here. Sign in to use it.')]);
        }

        if (User::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => __('This name belongs to someone with an account here. Sign in to use it, or choose another.')]);
        }
    }

    /**
     * The verdict, when it names a status a moderator may give; otherwise the
     * comment is held and the moderator's mistake reported, never the
     * comment lost.
     */
    private static function known(string $by, ModerationVerdict $verdict): ModerationVerdict
    {
        if (in_array($verdict->status, [ModerationVerdict::APPROVED, ModerationVerdict::PENDING, ModerationVerdict::SPAM], true)) {
            return $verdict;
        }

        report(new UnexpectedValueException("The comment moderator [{$by}] answered [{$verdict->status}]; the comment is held."));

        return new ModerationVerdict(ModerationVerdict::PENDING, $verdict->reason, [...$verdict->signals, 'answered' => $verdict->status]);
    }
}
