<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ListingViews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    addrChange(User::factory()->create()->assignRole('administrator'), ['patterns' => ['post' => '/{category}/{slug}/']]);
    $this->news = sdCategory('News');
    $this->local = sdCategory('Local', $this->news);
    $this->sport = sdCategory('Sport');
});

it('puts a post\'s primary category in its address, after its parents, and the first ticked category when none is chosen (I-869)', function (): void {
    $chosen = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->sport, $this->local]);
    $first = headPost($this->author, 'Ferry fares', [], [$this->sport, $this->local]);

    expect(addrCurrent('content', $chosen->id))->toBe('news/local/harbour-news')
        ->and(addrCurrent('content', $first->id))->toBe('sport/ferry-fares');
});

it('uses the first ticked category when the chosen one is no longer among the post\'s, in its address, its label and its section alike (I-869)', function (): void {
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->news->id], [$this->sport, $this->local]);

    expect(addrCurrent('content', $post->id))->toBe('sport/harbour-news')
        ->and(app(ArticleViews::class)->published($post)->kicker?->name)->toBe('Sport')
        ->and(sdNode(sdGraph('/sport/harbour-news/'), 'http://localhost/sport/harbour-news/#article')['articleSection'])->toBe('Sport');
});

it('moves a published post when its primary category changes, and its old address answers 301 to the new one (I-869, I-249)', function (): void {
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->sport, $this->local]);

    sdRepublish($post, $this->author, [BuiltInFields::PRIMARY_CATEGORY => $this->sport->id]);

    expect(addrCurrent('content', $post->id))->toBe('sport/harbour-news')
        ->and(addrLocation(addrGet('/news/local/harbour-news/')->assertStatus(301)))->toBe('/sport/harbour-news/');
});

it('moves it through Quick edit\'s fields too, when the change goes live at once (I-869)', function (): void {
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->sport, $this->local]);

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        contentId: $post->id,
        expectedRevisionId: $post->working_revision_id,
        actorId: (string) $this->author->id,
        fields: [BuiltInFields::PRIMARY_CATEGORY => $this->sport->id],
    ));

    expect(addrCurrent('content', $post->id))->toBe('sport/harbour-news');
});

it('shows the primary category above the title, on every card listing the post, and on its comments\' pages (I-869)', function (): void {
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->sport, $this->local]);

    expect(app(ArticleViews::class)->published($post)->kicker?->name)->toBe('Local')
        ->and(app(ListingViews::class)->items([$post->id])[0]->kicker?->name)->toBe('Local')
        ->and(app(ArticleViews::class)->headline($post)['kicker']?->name)->toBe('Local');
});

it('names the primary category as the article\'s section and in the {term} token (I-699, I-852)', function (): void {
    cmSettings(['title_type_post' => '{title} {sep} {term}'], SearchAndSocial::KEY);
    headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->sport, $this->local]);

    expect(sdNode(sdGraph('/news/local/harbour-news/'), 'http://localhost/news/local/harbour-news/#article')['articleSection'])->toBe('Local')
        ->and(headValue(headDom('/news/local/harbour-news/'), '//title'))->toBe('Harbour news – Local');
});

it('shows the editor the address a draft would take, its primary category in it (I-869)', function (): void {
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->sport, $this->local]);

    expect(EditorSession::for($post->id, (string) $this->author->id)?->addressPattern)->toBe('/news/local/{slug}/');
});
