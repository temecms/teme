<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Comments\CommentCountsStep;
use App\Domain\Import\Phases\Comments\CommentPagesStep;
use App\Domain\Import\Phases\Comments\CommentsPhase;
use App\Models\User;

/**
 * The comments import's test fixtures (I-341): a comment as the companion
 * sends one; a post as the posts phase leaves its map row; WordPress's
 * Discussion options; and a run of the comments phase and its two steps
 * alone. Shared by two test files, so they live in a class of their own.
 */
final class CommentImports
{
    /**
     * One comment as the companion sends it: a guest's approved comment on
     * post 20, written on 1 May 2024 at 10:00 UTC, but for $fields.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public static function item(int $id, array $fields = []): array
    {
        return [
            'id' => $id, 'post_id' => 20, 'parent' => 0, 'type' => 'comment', 'status' => '1',
            'author_name' => 'Ada Guest', 'author_email' => 'Ada@Example.com', 'author_url' => 'https://ada.example',
            'author_ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0', 'date_gmt' => '2024-05-01 10:00:00',
            'content' => "Lovely piece.\n\nThanks for writing it.", 'user_id' => 0,
            ...$fields,
        ];
    }

    /**
     * Post $wordpressId as the posts phase leaves it, by an earlier finished
     * run: imported as $item with its WordPress address's path, or, with no
     * item, failed or skipped as $outcome says. The map row's ID.
     */
    public static function post(ImportSource $source, int $wordpressId, ?Content $item, ?string $path = null, string $outcome = 'created'): string
    {
        return ImportRecords::entry($source->id, ImportRecords::run($source->id), 'post', (string) $wordpressId, $outcome, [
            'teme_type' => $item === null ? null : 'content',
            'teme_id' => $item?->id,
            'facts' => json_encode($path === null ? (object) [] : ['path' => $path], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The companion's settings, from its fixture, with these Discussion
     * options over WordPress's defaults.
     *
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    public static function settings(array $options): array
    {
        $settings = (array) json_decode((string) file_get_contents(base_path('tests/Fixtures/Import/Companion/settings.json')), true, flags: JSON_THROW_ON_ERROR);
        $settings['options'] = [...(array) $settings['options'], ...$options];

        return $settings;
    }

    /**
     * The comments phase and its two steps alone, run to their end over
     * these comments as $actor (the operator when null), with WordPress's
     * Discussion options changed when given.
     *
     * @param  list<array<string, mixed>>  $comments
     * @param  array<string, mixed>|null  $options
     */
    public static function run(FakeCompanion $companion, ImportSource $source, array $comments, ?array $options = null, ?User $actor = null): ImportRun
    {
        $companion->items('comments', $comments);

        if ($options !== null) {
            $companion->items('settings', self::settings($options));
        }

        importPhaseAlone(app(CommentsPhase::class), app(CommentCountsStep::class), app(CommentPagesStep::class));

        return importRun($source, $actor);
    }

    /**
     * WordPress comment $id's map row.
     */
    public static function entry(ImportSource $source, int $id): ImportMapEntry
    {
        return ImportMapEntry::query()->where('source_id', $source->id)->where('object_type', 'comment')->where('object_id', (string) $id)->firstOrFail();
    }

    /**
     * The Teme comment WordPress comment $id became.
     */
    public static function comment(ImportSource $source, int $id): Comment
    {
        return Comment::query()->findOrFail(self::entry($source, $id)->teme_id);
    }

    /**
     * The codes of WordPress comment $id's messages, in order.
     *
     * @return list<string>
     */
    public static function codes(ImportSource $source, int $id): array
    {
        return ImportMessageRecord::query()->where('map_id', self::entry($source, $id)->id)->orderBy('code')->pluck('code')->all();
    }
}
