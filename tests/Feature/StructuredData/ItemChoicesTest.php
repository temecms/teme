<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\StructuredData\TypeSchemas;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('describes an item as the page type and the article type it chose (I-709)', function (string $type, string $choice, ?string $pageType, ?string $entity): void {
    $fields = match ($choice) {
        'an About page' => [BuiltInFields::PAGE_TYPE => 'AboutPage'],
        'a Contact page' => [BuiltInFields::PAGE_TYPE => 'ContactPage'],
        'a news article' => [BuiltInFields::ARTICLE_TYPE => 'NewsArticle'],
        'no article' => [BuiltInFields::ARTICLE_TYPE => 'none'],
        default => [BuiltInFields::PAGE_TYPE => '', BuiltInFields::ARTICLE_TYPE => ''],
    };
    $item = lifecycleDraft($this->editor, 'Harbour news', $type);
    docSave($item, $this->editor, ['type' => 'doc', 'content' => []], $fields);
    listsPublish($item, $this->editor);

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, 'http://localhost/harbour-news/')['@type'] ?? null)->toBe($pageType)
        ->and(sdNode($graph, 'http://localhost/harbour-news/#article')['@type'] ?? null)->toBe($entity);
})->with([
    'a page that is an About page' => ['page', 'an About page', 'AboutPage', null],
    'a page that is a Contact page' => ['page', 'a Contact page', 'ContactPage', null],
    'a page that is a news article' => ['page', 'a news article', 'WebPage', 'NewsArticle'],
    'a post that is a news article' => ['post', 'a news article', 'WebPage', 'NewsArticle'],
    'a post that is no article' => ['post', 'no article', 'WebPage', null],
    'a post left to its type' => ['post', 'its type\'s default', 'WebPage', 'BlogPosting'],
]);

it('keeps the type\'s default for a choice its type doesn\'t offer, and refuses one that isn\'t on the list (I-709)', function (): void {
    $schemas = app(TypeSchemas::class);

    expect($schemas->mainEntity('post', [BuiltInFields::ARTICLE_TYPE => 'Recipe']))->toBe('BlogPosting')
        ->and($schemas->pageType('post', [BuiltInFields::PAGE_TYPE => 'ProfilePage']))->toBe('WebPage')
        ->and(fn () => refsDraft($this->editor, [BuiltInFields::PAGE_TYPE => 'ProfilePage']))->toThrow(ValidationException::class)
        ->and(fn () => refsDraft($this->editor, [BuiltInFields::ARTICLE_TYPE => 'Recipe']))->toThrow(ValidationException::class);
});
