<?php

declare(strict_types=1);

use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Head\PageHead;
use App\Domain\Themes\ViewIntent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
});

it('titles and describes a preview from the revision it shows, and the published page from its own (I-900)', function (): void {
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::SEO_TITLE => 'Old title', BuiltInFields::META_DESCRIPTION => 'What was decided.']);
    $current = ContentRevision::query()->findOrFail($post->working_revision_id);
    docSave($post, $this->author, (array) $current->document, [...(array) $current->fields, BuiltInFields::SEO_TITLE => 'New title', BuiltInFields::META_DESCRIPTION => 'What will be decided.']);
    $link = histPreview($post->refresh(), $this->author);

    $preview = headDom("/preview/{$link->token}");
    $published = headDom('/harbour-news/');

    expect(headValue($preview, '//title'))->toBe('New title')
        ->and(headValue($preview, '//head/meta[@name="description"]/@content'))->toBe('What will be decided.')
        ->and(headValue($published, '//title'))->toBe('Old title')
        ->and(headValue($published, '//head/meta[@name="description"]/@content'))->toBe('What was decided.');
});

it('gives an item\'s view model the revision it shows, and resolves its head from it with no statement of the item\'s own (I-900)', function (): void {
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::EXCERPT => 'Fares rise in spring.']);
    $view = app(ArticleViews::class)->published($post);
    // Another view model first, so the settings the head reads are already held.
    app(PageHead::class)->for(new DocumentPage(new ViewIntent('single', type: 'post'), app(ArticleViews::class)->published($post)));

    $statements = dashQueries(fn () => app(PageHead::class)->for(new DocumentPage(new ViewIntent('single', type: 'post'), $view)));

    expect($view->revisionId)->toBe((string) $post->published_revision_id)
        ->and($view->fields)->toEqual((array) $post->fields)
        ->and($view->wordCount)->toBe((int) DB::table('content_revisions')->where('id', $post->published_revision_id)->value('word_count'))
        ->and($view->revised)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($view->firstImageId)->toBeNull()
        ->and(implode("\n", $statements))->not->toContain('"contents"');
});
