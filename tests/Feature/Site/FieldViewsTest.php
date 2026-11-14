<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\SiteDates;
use App\Domain\Site\SiteLanguage;
use App\Domain\Site\ViewModels\FieldView;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Site\Movies;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = ctAdmin();
    lscrTimezone('Africa/Lagos');
    $this->language = app(SiteLanguage::class)->tag();
    Movies::type(['runtime' => 'before', 'rating' => 'after', 'trailer_url' => 'after', 'tagline' => 'after']);
    $this->sequel = Movies::movie($this->admin, 'Arrival II');
    $this->posterId = pageImage($this->admin, 800, 1200)->id;
});

/**
 * Arrival, with a value in every field but the second item and image.
 */
function fvArrival(): Content
{
    return Movies::movie(test()->admin, 'Arrival', [
        Movies::RELEASE => '2016-11-11', Movies::PREMIERE => '2016-09-01T18:30:00+00:00', Movies::RUNTIME => 116,
        Movies::RATING => 'pg-13', Movies::CINEMAS => true, Movies::TRAILER => 'https://example.com/t', Movies::TAGLINE => '',
        Movies::NOTES => "Line one\nLine two", Movies::POSTER => Movies::image(test()->posterId, 'The poster'), Movies::SEQUEL => test()->sequel->id,
    ]);
}

/**
 * The SQL of every statement the callback runs.
 *
 * @return list<string>
 */
function fvStatements(callable $callback): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $callback();

    return $statements;
}

it('gives each kind of field its plain value, its text and what to link or draw', function (): void {
    $movie = app(ArticleViews::class)->published(fvArrival());
    $language = $this->language;

    expect($movie->field('release_date'))->toBeInstanceOf(FieldView::class)
        ->and($movie->field('release_date')->type)->toBe('date')
        ->and($movie->field('release_date')->value->format('Y-m-d H:i e'))->toBe('2016-11-11 00:00 Africa/Lagos')
        ->and($movie->field('release_date')->text)->toBe(SiteDates::show(new DateTimeImmutable('2016-11-11', new DateTimeZone('Africa/Lagos')), $language, 'LL'))
        ->and($movie->field('premiere')->type)->toBe('datetime')
        ->and($movie->field('premiere')->text)->toBe(SiteDates::show(new DateTimeImmutable('2016-09-01 19:30', new DateTimeZone('Africa/Lagos')), $language, 'LLL'))
        ->and($movie->field('runtime')->value)->toBe(116)
        ->and((string) $movie->field('runtime'))->toBe('116')
        ->and($movie->field('rating')->value)->toBe('pg-13')
        ->and($movie->field('rating')->text)->toBe('PG-13')
        ->and($movie->field('in_cinemas')->value)->toBeTrue()
        ->and($movie->field('in_cinemas')->text)->toBe('Yes')
        ->and($movie->field('trailer_url')->url)->toBe('https://example.com/t')
        ->and($movie->field('notes')->value)->toBe("Line one\nLine two")
        ->and($movie->field('poster')->value)->toBe($this->posterId)
        ->and($movie->field('poster')->image?->id)->toBe($this->posterId)
        ->and($movie->field('poster')->text)->toBe('')
        ->and($movie->field('sequel')->value)->toBe($this->sequel->id)
        ->and($movie->field('sequel')->text)->toBe('Arrival II')
        ->and($movie->field('sequel')->url)->toBe('/movies/arrival-ii/');
});

it('says a field is empty, with an empty value, when nothing is stored or what it points at is gone', function (): void {
    $arrival = Movies::movie($this->admin, 'Arrival', [
        Movies::TAGLINE => '', Movies::CINEMAS => false,
        Movies::POSTER => Movies::image($this->posterId), Movies::SEQUEL => $this->sequel->id,
    ]);
    app(TrashMedia::class)->execute(new MediaActionInput($this->posterId, (string) $this->admin->id));
    app(UnpublishContent::class)->execute(new ContentActionInput($this->sequel->id, (string) $this->admin->id));

    $movie = app(ArticleViews::class)->published($arrival->refresh());

    expect([$movie->field('tagline')->empty, $movie->field('tagline')->value, $movie->field('tagline')->text])->toBe([true, '', ''])
        ->and([$movie->field('runtime')->empty, $movie->field('runtime')->value])->toBe([true, null])
        ->and([$movie->field('in_cinemas')->empty, $movie->field('in_cinemas')->value, $movie->field('in_cinemas')->text])->toBe([true, false, ''])
        ->and([$movie->field('poster')->empty, $movie->field('poster')->value, $movie->field('poster')->image])->toBe([true, null, null])
        ->and([$movie->field('sequel')->empty, $movie->field('sequel')->value, $movie->field('sequel')->url, $movie->field('sequel')->text])->toBe([true, null, null, '']);
});

it('keeps answering core’s fields, and nothing for a handle the type hasn’t got', function (): void {
    $post = pagePublish($this->admin, 'Fields', [], [BuiltInFields::EXCERPT => 'A summary'], [], null);
    $page = lifecycleDraft($this->admin, 'About', 'page')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($page->id, 'About', ['type' => 'doc', 'content' => []], [BuiltInFields::PAGE_TYPE => 'AboutPage'], $page->working_revision_id, (string) $this->admin->id));
    listsPublish($page, $this->admin);

    $postView = app(ArticleViews::class)->published($post->refresh());
    $pageView = app(ArticleViews::class)->published($page->refresh());

    expect($postView->field('excerpt')->value)->toBe('A summary')
        ->and([$pageView->field('page_type')->value, $pageView->field('page_type')->text])->toBe(['AboutPage', 'About page'])
        ->and($postView->field('canonical'))->toBeNull()
        ->and($postView->field('primary_category'))->toBeNull()
        ->and($postView->field('no_such_field'))->toBeNull();
});

it('lists the shown fields, before or after or both, skipping empty ones', function (): void {
    $movie = app(ArticleViews::class)->published(fvArrival());
    $handles = fn (array $views): array => array_map(fn (FieldView $view): string => $view->handle, $views);

    expect($handles($movie->shownFields('before')))->toBe(['runtime'])
        ->and($handles($movie->shownFields('after')))->toBe(['rating', 'trailer_url'])
        ->and($handles($movie->shownFields()))->toBe(['runtime', 'rating', 'trailer_url'])
        ->and($movie->shownFields())->each->toBeInstanceOf(FieldView::class);
});

it('reads an article’s items and images in one statement each, whatever the number of fields', function (): void {
    $prequel = Movies::movie($this->admin, 'Arrival 0');
    $backdrop = pageImage($this->admin, 1600, 900)->id;
    $full = Movies::movie($this->admin, 'Full', [
        Movies::SEQUEL => $this->sequel->id, Movies::PREQUEL => $prequel->id,
        Movies::POSTER => Movies::image($this->posterId), Movies::BACKDROP => Movies::image($backdrop),
    ]);
    $bare = Movies::movie($this->admin, 'Bare');

    // Each page drawn once first, then each counted afresh, as a request
    // starts with nothing remembered.
    app(ArticleViews::class)->published($bare);
    app(ArticleViews::class)->published($full);
    app()->forgetScopedInstances();
    $without = fvStatements(fn () => app(ArticleViews::class)->published($bare));
    app()->forgetScopedInstances();
    $with = fvStatements(fn () => app(ArticleViews::class)->published($full));

    expect(count($with))->toBe(count($without) + 2)
        ->and(array_values(array_filter($with, fn (string $sql): bool => str_contains($sql, 'from "media"'))))->toHaveCount(1);
});

it('records the page tag of each item a field points at', function (): void {
    $arrival = fvArrival();
    app(RenderRecord::class)->start(0);

    app(ArticleViews::class)->published($arrival);

    expect(app(RenderRecord::class)->tags())->toContain(PageTags::content($this->sequel->id));
});

it('reads no field registry when a template asks for a field', function (): void {
    $movie = app(ArticleViews::class)->published(fvArrival());
    app()->forgetInstance(FieldRegistry::class);
    app()->bind(FieldRegistry::class, fn () => throw new RuntimeException('A template asked the field registry.'));

    expect($movie->field('runtime')->value)->toBe(116);
});
