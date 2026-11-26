<?php

declare(strict_types=1);

use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\Types\FeaturedPostsCard;
use App\Domain\Regions\Regions;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cards\CardRegion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('tells a core card\'s view the region\'s name as a string, and the region\'s other values as regionInfo', function (): void {
    pageArticle($this->editor, 'A story');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/open')]]);
    themeActivate('open');
    $html = (new FeaturedPostsCard)->renderHtml([], new RenderContext(app(LinkResolver::class), region: new CardRegion('sidebar', 'open', '300x600', 300, 600)));

    expect($html)->toContain('data-region="sidebar"')->toContain('data-region-compare="yes"')->toContain('data-theme="open"')->toContain('data-size="300x600"');
});

it('gives a card drawn outside any region an empty slug, never nothing', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/open')]]);
    themeActivate('open');
    pageArticle($this->editor, 'A story');
    $html = (new FeaturedPostsCard)->renderHtml([], new RenderContext(app(LinkResolver::class)));

    expect($html)->toContain('data-region=""')->toContain('data-theme="open"');
});

it('lets a view compare the region with its name', function (): void {
    expect((string) new CardRegion('sidebar', 'good'))->toBe('sidebar')
        ->and(new CardRegion('sidebar', 'good') == 'sidebar')->toBeTrue();
});

it('asks whether a region has anything to draw, true only for one that is published with content', function (): void {
    $template = '@regionHasContent("after-content")has-content @else empty @endregionHasContent';

    expect(trim(Blade::render($template)))->toBe('empty');

    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Join the newsletter']]]]);

    // A draft draws nothing, so it has nothing.
    expect(trim(Blade::render($template)))->toBe('empty');

    listsPublish($region, $this->editor);
    app(Regions::class)->start();

    expect(trim(Blade::render($template)))->toBe('has-content');
});

it('says a region the theme does not declare has nothing', function (): void {
    expect(trim(Blade::render('@regionHasContent("nowhere")yes @else no @endregionHasContent')))->toBe('no');
});

it('reads the region once for the check and the drawing together', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Join the newsletter']]]]);
    listsPublish($region, $this->editor);
    app(Regions::class)->start();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Blade::render('@regionHasContent("after-content")<x-teme::region name="after-content" />@endregionHasContent');
    $both = $queries;
    $queries = 0;
    app(Regions::class)->start();
    Blade::render('<x-teme::region name="after-content" />');

    expect($both)->toBe($queries);
});
