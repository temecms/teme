<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Comments;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Domain\Site\PathResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * WordPress's comment pages, `/slug/comment-page-N/`, redirected to where
 * their comments are now (I-610): Teme's own page N when WordPress's paging
 * came across, the item otherwise, each through the import's one redirect
 * Operation. At most 200 items and 1,000 redirects a job, carrying on at the
 * last item's ID.
 */
final readonly class CommentPagesStep implements StepPhase
{
    /** The most redirects one job asks for, so it ends well inside its 75 seconds. */
    public const int MOST = 1000;

    /** The most items one job reads. */
    private const int ITEMS = 200;

    /** Settings › Discussion's largest page (app/Domain/Comments/DiscussionSettingsGroup.php). */
    private const int MOST_PER_PAGE = 200;

    public function key(): string
    {
        return 'comment-pages';
    }

    public function order(): int
    {
        return 790;
    }

    public function label(): string
    {
        return 'Comment pages';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $options = $reader->whole('settings')['options'] ?? null;
        $paging = self::paging(is_array($options) ? $options : []);

        if ($paging === null) {
            return StepResult::done();
        }

        $items = $this->items($context->sourceId, $cursor ?? '');
        $pages = [];
        $last = null;
        $taken = 0;

        foreach ($items as $item) {
            $mine = self::pagesOf($item, $paging);

            if ($pages !== [] && count($pages) + count($mine) > self::MOST) {
                break;
            }

            array_push($pages, ...$mine);
            $last = (string) $item->content_id;
            $taken++;
        }

        $refused = [];

        foreach ($pages as $page) {
            try {
                // A redirect already at the address is kept, whoever made it: its outcome needs nothing more here.
                app(ImportRedirect::class)->execute(new ImportRedirectInput(
                    from: $page['from'],
                    to: $page['to'] ?? '',
                    contentId: $page['to'] === null ? $page['contentId'] : null,
                    actorId: $context->actorId(),
                ));
            } catch (ValidationException) {
                $refused[] = $page['from'];
            }
        }

        $messages = $refused === [] ? [] : [self::refused($refused)];
        $more = $taken < count($items) || count($items) === self::ITEMS;

        return $last !== null && $more ? StepResult::continueAt($last, $messages) : StepResult::done($messages);
    }

    /**
     * How WordPress numbered its comment pages, from its Discussion options
     * (I-610), or null when it had none: only with "Break comments into
     * pages" on.
     *
     * @param  array<array-key, mixed>  $options
     *
     * @return array{perPage: int, threaded: bool, cameAcross: bool}|null
     */
    private static function paging(array $options): ?array
    {
        $perPage = (int) ($options['comments_per_page'] ?? 0);

        if (! self::on($options['page_comments'] ?? null) || $perPage < 1) {
            return null;
        }

        $threaded = self::on($options['thread_comments'] ?? null) && (int) ($options['thread_comments_depth'] ?? 0) > 1;

        return ['perPage' => $perPage, 'threaded' => $threaded, 'cameAcross' => $threaded && $perPage <= self::MOST_PER_PAGE];
    }

    /**
     * A WordPress checkbox option that's ticked.
     */
    private static function on(mixed $value): bool
    {
        return in_array($value, ['1', 1, true], true);
    }

    /**
     * The next items after $after holding this source's comments, by ID,
     * each with its approved comments, those that start a conversation, its
     * WordPress path from its post's map row, and its Teme path and type.
     *
     * @return list<stdClass>
     */
    private function items(string $sourceId, string $after): array
    {
        // The map's Teme IDs are text; cast to the comments' own type, so their key serves the join.
        /** @var list<stdClass> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT counted.content_id, counted.approved, counted.conversations,
                   post.facts->>'path' AS wordpress_path, path.path AS current_path, item.type
            FROM (
                SELECT c.content_id,
                       count(*) FILTER (WHERE c.status = 'approved') AS approved,
                       count(*) FILTER (WHERE c.status = 'approved' AND c.depth = 1) AS conversations
                FROM import_map m
                JOIN comments c ON c.id = m.teme_id::char(26)
                WHERE m.source_id = ? AND m.object_type = 'comment' AND m.teme_id IS NOT NULL AND c.content_id > ?
                GROUP BY c.content_id
                ORDER BY c.content_id
                LIMIT ?
            ) counted
            JOIN contents item ON item.id = counted.content_id
            LEFT JOIN import_map post ON post.source_id = ? AND post.object_type = 'post'
                AND post.teme_type = 'content' AND post.teme_id = counted.content_id::text
            LEFT JOIN paths path ON path.target_type = 'content' AND path.target_id = counted.content_id::text AND path.is_current
            ORDER BY counted.content_id
            SQL, [$sourceId, $after, self::ITEMS, $sourceId]);

        return $rows;
    }

    /**
     * The redirects for one item's comment pages, page 1 first.
     *
     * @param  array{perPage: int, threaded: bool, cameAcross: bool}  $paging
     *
     * @return list<array{from: string, contentId: string, to: string|null}>
     */
    private static function pagesOf(stdClass $item, array $paging): array
    {
        $current = $item->current_path === null ? null : (string) $item->current_path;
        $from = $item->wordpress_path === null ? $current : PublicRequestPath::normalise((string) $item->wordpress_path);

        if ($from === null || $from === '') {
            return [];
        }

        $count = (int) ($paging['threaded'] ? $item->conversations : $item->approved);
        $last = min((int) ceil($count / $paging['perPage']), PathResolver::LAST_PAGE);
        $pages = [];

        for ($number = 1; $number <= $last; $number++) {
            $pages[] = [
                'from' => '/'.$from.'/comment-page-'.$number.'/',
                'contentId' => (string) $item->content_id,
                // Page 1, and every page whose numbering didn't come across, is the item by its ID (I-610).
                'to' => $paging['cameAcross'] && $number > 1 && $current !== null ? Addresses::toUrl($current.'/comments/page/'.$number, (string) $item->type) : null,
            ];
        }

        return $pages;
    }

    /**
     * The message for the addresses no redirect could be made from.
     *
     * @param  non-empty-list<string>  $paths
     */
    private static function refused(array $paths): ImportMessage
    {
        $count = count($paths);
        $shown = array_map(fn (string $path): string => mb_substr('/'.trim($path, '/').'/', 0, 60), array_slice($paths, 0, 10));
        $named = implode(', ', $shown).($count > 10 ? ' and '.($count - 10).' more' : '');

        return new ImportMessage(
            'comment-pages.refused',
            $count === 1
                ? "Teme couldn’t redirect 1 of WordPress’s comment page addresses, because a redirect there would clash with another: {$named}."
                : "Teme couldn’t redirect {$count} of WordPress’s comment page addresses, because a redirect there would clash with others: {$named}.",
            ['count' => $count, 'paths' => $shown],
        );
    }
}
