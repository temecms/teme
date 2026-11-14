<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Queries\LinkTargets;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('draws a published region where the theme places it', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Join the newsletter']]]]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    expect(addrGet('/a-story/')->assertOk()->getContent())->toContain('data-region="after-content"')->toContain('Join the newsletter');
});

it('draws nothing for a region that is only a draft', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Not yet']]]]);
    pageArticle($this->editor, 'A story');

    expect(addrGet('/a-story/')->assertOk()->getContent())->not->toContain('Not yet');
});

it('stops drawing a region once it is unpublished, and draws it again when republished', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Join the newsletter']]]]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');
    expect(addrGet('/a-story/')->assertOk()->getContent())->toContain('Join the newsletter');

    app(UnpublishContent::class)->execute(new ContentActionInput($region->id, (string) $this->editor->id));
    expect(addrGet('/a-story/')->assertOk()->getContent())->not->toContain('Join the newsletter');

    listsPublish($region, $this->editor);
    expect(addrGet('/a-story/')->assertOk()->getContent())->toContain('Join the newsletter');
});

it('loads the style of a card used only in a region', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [regionCardNode('button')]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    expect(addrGet('/a-story/')->assertOk()->getContent())->toContain('data-teme-card="button"');
});

it('never gives a region an address of its own, or offers it as a link', function (): void {
    $region = regionCreate($this->editor);
    listsPublish($region, $this->editor);

    addrGet('/good--after-content/')->assertNotFound();
    expect(addrCurrent('content', $region->id))->toBeNull()
        ->and(array_column(LinkTargets::search(''), 'id'))->not->toContain($region->id);
});

it('keeps an inactive theme\'s regions when the theme changes', function (): void {
    $region = regionCreate($this->editor);
    themeActivate('default');

    expect(Content::query()->find($region->id))->not->toBeNull();
});
