<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\StructuredData\PageAndArticleTypes;
use App\Domain\StructuredData\TypeSchema;
use App\Domain\StructuredData\TypeSchemas;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->setting = fn (string $key): ?Setting => collect(app(SettingsRegistry::class)->find(SearchAndSocial::KEY)?->settings ?? [])->firstWhere('key', $key);
});

it('gives each public type a page type and an article type, after who publishes the site, set to what the type declares (I-709)', function (): void {
    $keys = array_map(fn (SearchAndSocialSection $section): string => $section->key(), SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)));

    expect(array_values(array_intersect($keys, ['who-publishes', 'page-and-article-types'])))->toBe(['who-publishes', 'page-and-article-types'])
        ->and([($this->setting)('schema_page_type_post')->label, ($this->setting)('schema_page_type_post')->default])->toBe(['Each post is', 'WebPage'])
        ->and(($this->setting)('schema_page_type_post')->options)->toBe(['WebPage' => 'A web page', 'AboutPage' => 'An About page', 'ContactPage' => 'A contact page', 'CollectionPage' => 'A collection page'])
        ->and([($this->setting)('schema_article_type_post')->label, ($this->setting)('schema_article_type_post')->default])->toBe(['Article kind for posts', 'BlogPosting'])
        ->and(($this->setting)('schema_article_type_post')->options)->toBe(['Article' => 'An article', 'NewsArticle' => 'A news article', 'BlogPosting' => 'A blog post', 'none' => 'No article'])
        ->and(($this->setting)('schema_article_type_page')->default)->toBe('none');
});

it('describes a type\'s items as the site chose for the type, an item\'s own choice still first (I-709)', function (): void {
    cmSettings([PageAndArticleTypes::articleKey('post') => 'NewsArticle', PageAndArticleTypes::pageKey('page') => 'AboutPage'], SearchAndSocial::KEY);
    $news = lifecycleDraft($this->editor, 'Harbour news');
    listsPublish($news, $this->editor);
    $blog = lifecycleDraft($this->editor, 'Ferry fares');
    docSave($blog, $this->editor, ['type' => 'doc', 'content' => []], [BuiltInFields::ARTICLE_TYPE => 'BlogPosting']);
    listsPublish($blog, $this->editor);
    addrPublished($this->editor, 'About us', 'page');

    expect(sdNode(sdGraph('/harbour-news/'), 'http://localhost/harbour-news/#article')['@type'] ?? null)->toBe('NewsArticle')
        ->and(sdNode(sdGraph('/ferry-fares/'), 'http://localhost/ferry-fares/#article')['@type'] ?? null)->toBe('BlogPosting')
        ->and(sdNode(sdGraph('/about-us/'), 'http://localhost/about-us/')['@type'] ?? null)->toBe('AboutPage');
});

it('keeps the type\'s own entry for a stored choice the type doesn\'t offer, as an import could leave it (I-709)', function (): void {
    cmSettings([PageAndArticleTypes::pageKey('post') => 'ProfilePage', PageAndArticleTypes::articleKey('page') => 'Recipe'], SearchAndSocial::KEY);
    $schemas = app(TypeSchemas::class);

    expect([$schemas->for('post')->pageType, $schemas->for('post')->mainEntity])->toBe(['WebPage', 'BlogPosting'])
        ->and($schemas->for('page')->mainEntity)->toBeNull();
});

it('gives a type registered later its own two choices once Search and social is made again (I-709)', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('talk', 'Talk', 'Talks', 'talks', schema: new TypeSchema(mainEntity: 'Event', mainEntities: ['Event'])));
    app(SettingsRegistry::class)->replace(SearchAndSocial::group(app()->tagged(SearchAndSocial::SECTIONS)));

    expect(($this->setting)('schema_article_type_talk')?->options)->toBe(['Event' => 'Event', 'none' => 'Nothing'])
        ->and(($this->setting)('schema_page_type_talk')?->default)->toBe('WebPage');
});
