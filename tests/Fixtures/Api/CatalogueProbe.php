<?php

declare(strict_types=1);

namespace Tests\Fixtures\Api;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the catalogue's tests share: counting the statements a request makes,
 * changing General's settings as an administrator does, and publishing a
 * movie that shows a given file. Kept here, not in tests/Pest.php, which
 * other slices change at the same time.
 */
final class CatalogueProbe
{
    /**
     * How many SQL statements $run makes.
     */
    public static function statements(Closure $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $run();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /**
     * Sets these keys of General, through UpdateSettings, elevated.
     *
     * @param  array<string, mixed>  $values
     */
    public static function general(User $admin, array $values): void
    {
        $version = (int) DB::table('settings')->where('group_key', 'general')->value('version');

        scElevated(fn (): mixed => app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', $values, [], [], $version, (string) $admin->id)));
    }

    /**
     * A movie by this author with these fields, body and terms, published
     * by the administrator unless $publish is false.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $document
     * @param  list<string>  $terms
     */
    public static function publishedMovie(User $admin, User $author, string $title, array $fields, array $document, array $terms = [], bool $publish = true): Content
    {
        $movie = lifecycleDraft($author, $title, 'movie');
        $movie->refresh();
        app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, $document, $fields, $movie->working_revision_id, (string) $author->id, $terms));

        if ($publish) {
            listsPublish($movie, $admin);
        }

        return $movie->refresh();
    }

    /**
     * A body of one image card of this file.
     *
     * @return array<string, mixed>
     */
    public static function imageBody(string $mediaId): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['id' => strtoupper((string) Str::ulid()), 'mediaId' => $mediaId, 'alt' => 'In the body']]]];
    }

    /**
     * A body of one paragraph whose words link to this file by its ID.
     *
     * @return array<string, mixed>
     */
    public static function linkBody(string $mediaId): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The file', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:media/'.$mediaId]]]]]]]];
    }

    /**
     * A body of one paragraph.
     *
     * @return array<string, mixed>
     */
    public static function plainBody(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Words.']]]]];
    }

    /**
     * A problem answer's members but for its `instance`, for comparing a
     * thing that is hidden with one that never existed.
     *
     * @param  array<string, mixed>  $problem
     *
     * @return array<string, mixed>
     */
    public static function withoutInstance(array $problem): array
    {
        unset($problem['instance']);

        return $problem;
    }
}
