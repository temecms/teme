<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Site\ArticleViews;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

it('records each ancestor\'s and each child\'s content tag and the children list', function (): void {
    app(RenderRecord::class)->start(0);
    app(ArticleViews::class)->published($this->tree->team->refresh());

    expect(app(RenderRecord::class)->tags())->toContain(
        PageTags::content($this->tree->about->id),
        PageTags::content($this->tree->people->id),
        PageTags::childrenOf($this->tree->team->id),
    );
});

it('sets aside a child\'s page when its parent is renamed', function (): void {
    $folder = cacheOn();
    addrGet('/about/team/people/');
    $about = $this->tree->about->refresh();

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        contentId: $about->id,
        expectedRevisionId: (string) $about->working_revision_id,
        actorId: (string) $this->tree->editor->id,
        title: 'About us',
    ));

    expect(cacheKept('/about/team/people/'))->toBe([]);
    cacheOff($folder);
});

it('sets aside the parent\'s page when a child is published under it', function (): void {
    $folder = cacheOn();
    addrGet('/about/');
    $careers = lifecycleDraft($this->tree->editor, 'Careers', 'page');
    PageTree::place($careers, $this->tree->about, 5);

    expect(cacheKept('/about/'))->toBe(['/about/']);

    listsPublish($careers, $this->tree->editor);

    expect(cacheKept('/about/'))->toBe([]);
    cacheOff($folder);
});
