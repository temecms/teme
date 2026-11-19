<?php

/**
 * Brings one WordPress comment, pingback or trackback into Teme for an
 * import (I-341): on its item, under the comment it replied to at any depth,
 * with WordPress's status, date, number and words, and its writer's IP
 * address and browser only while keep_ip_days allows. No moderator judges
 * it and no email is sent; the import rebuilds comment_stats once after its
 * comments. Only an importer may.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\CommentDocument;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\Input\ImportCommentInput;
use App\Domain\Contents\Models\Content;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Uid\Ulid;
use Teme\Contracts\Operations\OperationInput;

final class ImportComment extends BaseOperation
{
    use AuthorizesImports;

    /** The statuses an imported comment may have. */
    public const array STATUSES = [CommentStatus::PENDING, CommentStatus::APPROVED, CommentStatus::SPAM, CommentStatus::TRASH];

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportCommentInput $input */
        self::checkFields($input->status, $input->authorName, $input->authorEmail, $input->authorUrl, $input->authorUserId, $input->body);

        Validator::make(
            ['legacyId' => $input->legacyId, 'writtenAt' => $input->writtenAt->getTimestamp()],
            ['legacyId' => ['integer', 'min:1'], 'writtenAt' => ['integer', 'min:0']],
            [
                'legacyId.min' => __('A WordPress comment number is a whole number above 0.'),
                'writtenAt.min' => __('A comment’s date is after 1 January 1970.'),
            ],
        )->validate();

        $item = Content::withTrashed()->find($input->contentId);

        if ($item === null) {
            throw ValidationException::withMessages(['contentId' => __('The post or page isn’t there to comment on.')]);
        }

        $types = app(ContentTypeRegistry::class);

        if (! $types->has($item->type) || $types->get($item->type)->comments === null) {
            throw ValidationException::withMessages(['contentId' => __('Its post or page takes no comments.')]);
        }
    }

    /**
     * @return array{commentId: string, legacyKept: bool, editedAt: CarbonImmutable}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportCommentInput $input */
        // Locked before it's read, so it can't be taken down meanwhile.
        $parent = $input->parentId === null ? null : Comment::query()->lockForUpdate()->find($input->parentId);

        if ($input->parentId !== null && ($parent === null || $parent->content_id !== $input->contentId)) {
            throw ValidationException::withMessages(['parentId' => __('The comment it replies to isn’t on the same post or page.')]);
        }

        $written = $input->writtenAt->utc()->startOfSecond();
        $id = self::idFor($written, $input->legacyId);
        $legacyKept = ! Comment::query()->where('legacy_id', $input->legacyId)->exists();
        // An address older than keep_ip_days is never brought (I-270).
        $recent = $written->greaterThan(CarbonImmutable::now()->subDays(DiscussionSettings::current()->keepIpDays));
        $agent = trim((string) $input->userAgent);

        try {
            Comment::query()->create([
                'id' => $id,
                'content_id' => $input->contentId,
                'parent_id' => $parent?->id,
                'path' => ($parent === null ? '' : $parent->path).$id.'/',
                'depth' => ($parent === null ? 0 : $parent->depth) + 1,
                'kind' => $input->kind,
                'status' => $input->status,
                'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
                'author_name' => $input->authorName,
                'author_email' => $input->authorEmail,
                'author_url' => $input->authorUrl,
                'author_user_id' => $input->authorUserId,
                'body' => $input->body,
                'ip' => $recent && filter_var((string) $input->ip, FILTER_VALIDATE_IP) !== false ? $input->ip : null,
                'user_agent' => $recent && $agent !== '' ? mb_substr($agent, 0, 255) : null,
                'legacy_id' => $legacyKept ? $input->legacyId : null,
                'moderation' => null,
                // Both always set (database/migrations/2026_11_20_100000_require_comment_timestamps.php): WordPress's date.
                'created_at' => $written,
                'updated_at' => $written,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['legacyId' => __('Another comment took this WordPress number at the same moment. The next run tries again.')]);
        } catch (QueryException $refused) {
            // Its message holds the row's values, an email and an IP address among them (I-270).
            throw new RuntimeException("PostgreSQL refused the comment’s row ({$refused->getCode()}).");
        }

        return ['commentId' => $id, 'legacyKept' => $legacyKept, 'editedAt' => $written];
    }

    /**
     * Refuses a status, a writer or words no imported comment may have;
     * UpdateImportedComment asks the same.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ValidationException
     */
    public static function checkFields(string $status, ?string $name, #[SensitiveParameter] ?string $email, ?string $url, ?int $userId, array $body): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('A comment’s status is pending, approved, spam or trash.')]);
        }

        Validator::make(
            ['authorName' => $name, 'authorEmail' => $email, 'authorUrl' => $url],
            ['authorName' => ['nullable', 'string', 'max:255'], 'authorEmail' => ['nullable', 'string', 'max:254'], 'authorUrl' => ['nullable', 'string', 'max:200']],
        )->validate();

        if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
            throw ValidationException::withMessages(['authorUserId' => __('The person who wrote it isn’t on Teme.')]);
        }

        CommentDocument::validate($body);
    }

    /**
     * The comment's ID (I-590): a lower-case ULID whose time is when
     * WordPress says it was written, then its WordPress number, then five
     * random bytes, so comments of one second sort in WordPress's order in
     * any job, and two sites' comments never share one.
     */
    private static function idFor(CarbonImmutable $written, int $legacyId): string
    {
        $time = substr(pack('J', $written->getTimestamp() * 1000), 2);
        $number = $legacyId < 2 ** 40 ? substr(pack('J', $legacyId), 3) : random_bytes(5);

        return strtolower(Ulid::fromBinary($time.$number.random_bytes(5))->toBase32());
    }
}
