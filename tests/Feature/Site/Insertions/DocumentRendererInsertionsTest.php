<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Site\Insertions\InsertionPlan;
use App\Domain\Site\Insertions\PlanBuilder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\Position;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');

    $this->document = docWith(
        docText('One'),
        docText('Two'),
        docText('Three'),
    );
    $this->baseline = app(DocumentRenderer::class)->html($this->document);
});

it('draws the insertion exactly between the right blocks and is otherwise byte-identical to today', function (): void {
    $plan = PlanBuilder::for($this->document, new ArticleFacts('c', 'post', [], 'en', [], 3, 3), [
        new Insertion('mid', Position::afterParagraph(2), '<aside data-test="ins">Ad</aside>'),
    ]);

    $with = app(DocumentRenderer::class)->html($this->document, new RenderContext(
        app(LinkResolver::class),
        insertions: $plan,
    ));

    expect($with)->toBe('<p>One</p><p>Two</p><aside data-test="ins">Ad</aside><p>Three</p>')
        ->and(str_replace('<aside data-test="ins">Ad</aside>', '', $with))->toBe($this->baseline);
});

it('is identical to today with target feed or with no plan', function (): void {
    $plan = new InsertionPlan([0 => ['<aside>x</aside>']]);

    expect(app(DocumentRenderer::class)->html($this->document))->toBe($this->baseline)
        ->and(app(DocumentRenderer::class)->html($this->document, new RenderContext(
            app(LinkResolver::class),
            target: 'feed',
            insertions: $plan,
        )))->toBe($this->baseline)
        ->and(app(DocumentRenderer::class)->html($this->document, new RenderContext(
            app(LinkResolver::class),
            insertions: null,
        )))->toBe($this->baseline);
});

it('leaves the editor\'s JSON untouched when drawing with a plan', function (): void {
    $before = json_encode($this->document);
    $plan = PlanBuilder::for($this->document, new ArticleFacts('c', 'post', [], 'en', [], 3, 3), [
        new Insertion('mid', Position::afterParagraph(1), '<aside>x</aside>'),
    ]);

    app(DocumentRenderer::class)->html($this->document, new RenderContext(
        app(LinkResolver::class),
        insertions: $plan,
    ));

    expect(json_encode($this->document))->toBe($before);
});
