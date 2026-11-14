<?php

declare(strict_types=1);

use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\RestoreRevisionInput;
use App\Domain\Contents\Operations\RestoreRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\PathResolver;
use App\Domain\Themes\TemplateResolver;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // The good fixture declares one template, for pages: full-width, with its file.
    // The plain fixture declares none, and has single but no single-page.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/plain')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('resolves a page with full-width chosen to single-page-full-width, and a page with none from single-page down', function (): void {
    $wide = addrPublished($this->editor, 'Wide page', 'page');
    docSave($wide, $this->editor, docWith(docText('Wide.')), [BuiltInFields::TEMPLATE => 'full-width']);
    listsPublish($wide, $this->editor);
    addrPublished($this->editor, 'Plain page', 'page');

    $chosen = PathResolver::resolve('/wide-page/', '')['intent'];
    $plain = PathResolver::resolve('/plain-page/', '')['intent'];

    expect($chosen->template)->toBe('full-width')
        ->and(app(TemplateResolver::class)->resolve($chosen)->view)->toBe('theme::templates.single-page-full-width')
        ->and($plain->template)->toBeNull()
        ->and(array_column(app(TemplateResolver::class)->resolve($plain)->candidates, 'name'))->toBe(['templates.single-page', 'templates.single']);
});

it('keeps the published template until the revision that changes it is published', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    docSave($about, $this->editor, docWith(docText('About us.')), [BuiltInFields::TEMPLATE => 'full-width']);

    expect(PathResolver::resolve('/about/', '')['intent']->template)->toBeNull();

    listsPublish($about, $this->editor);

    expect(PathResolver::resolve('/about/', '')['intent']->template)->toBe('full-width');
});

it('refuses a newly chosen template the active theme does not offer for the type', function (): void {
    $about = lifecycleDraft($this->editor, 'About', 'page');
    $news = lifecycleDraft($this->editor, 'News');
    $create = fn () => app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'page',
        title: 'Landing',
        document: docWith(docText('x')),
        fields: [BuiltInFields::TEMPLATE => 'landing'],
        actorId: (string) $this->editor->id,
    ));

    expect(fn () => docSave($about, $this->editor, docWith(docText('x')), [BuiltInFields::TEMPLATE => 'landing']))->toThrow(ValidationException::class)
        ->and(fn () => edSave($about->id, $this->editor, docWith(docText('x')), fields: [BuiltInFields::TEMPLATE => 'landing']))->toThrow(ValidationException::class)
        // full-width is declared for pages, not posts.
        ->and(fn () => docSave($news, $this->editor, docWith(docText('x')), [BuiltInFields::TEMPLATE => 'full-width']))->toThrow(ValidationException::class)
        ->and($create)->toThrow(ValidationException::class)
        ->and(docSave($about->refresh(), $this->editor, docWith(docText('x')), [BuiltInFields::TEMPLATE => 'full-width'])->workingRevision->fields[BuiltInFields::TEMPLATE])->toBe('full-width');
});

it('keeps a stored template the current theme lacks, so saving other edits never fails', function (): void {
    $about = lifecycleDraft($this->editor, 'About', 'page');
    docSave($about, $this->editor, docWith(docText('First.')), [BuiltInFields::TEMPLATE => 'full-width']);
    themeActivate('plain');

    $saved = docSave($about->refresh(), $this->editor, docWith(docText('Second.')));

    expect($saved->workingRevision->fields[BuiltInFields::TEMPLATE])->toBe('full-width')
        ->and(fn () => docSave($saved, $this->editor, docWith(docText('Third.')), [BuiltInFields::TEMPLATE => 'wide']))->toThrow(ValidationException::class)
        ->and(docSave($saved->refresh(), $this->editor, docWith(docText('Third.')), [BuiltInFields::TEMPLATE => ''])->workingRevision->fields[BuiltInFields::TEMPLATE])->toBe('');
});

it('passes over a stored template the theme lacks when it resolves the page', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    docSave($about, $this->editor, docWith(docText('About us.')), [BuiltInFields::TEMPLATE => 'full-width']);
    listsPublish($about, $this->editor);
    themeActivate('plain');

    $resolution = app(TemplateResolver::class)->resolve(PathResolver::resolve('/about/', '')['intent']);

    expect(array_column($resolution->candidates, 'name'))->toBe(['templates.single-page-full-width', 'templates.single-page', 'templates.single'])
        ->and($resolution->view)->toBe('theme::templates.single');
});

it('restores an old revision without failing over a template the current theme lacks', function (): void {
    $about = lifecycleDraft($this->editor, 'About', 'page');
    $wide = docSave($about, $this->editor, docWith(docText('Wide.')), [BuiltInFields::TEMPLATE => 'full-width'])->working_revision_id;
    docSave($about->refresh(), $this->editor, docWith(docText('Plain.')), [BuiltInFields::TEMPLATE => '']);
    themeActivate('plain');

    $restored = app(RestoreRevision::class)->execute(new RestoreRevisionInput($about->id, $wide, (string) $this->editor->id));

    // The working revision's template stands in for one the theme no longer offers.
    expect($restored->workingRevision->restored_from_id)->toBe($wide)
        ->and($restored->workingRevision->fields[BuiltInFields::TEMPLATE])->toBe('');
});

it('gives the editor the active theme\'s templates for the item\'s type, and none for a type it declares none for', function (): void {
    $about = lifecycleDraft($this->editor, 'About', 'page');
    $news = lifecycleDraft($this->editor, 'News');

    $this->actingAs($this->editor)->get("/admin/content/{$about->id}/edit")
        ->assertInertia(fn (Assert $screen) => $screen->where('session.templates', [['slug' => 'full-width', 'label' => 'Full width']]));
    $this->actingAs($this->editor)->get("/admin/content/{$news->id}/edit")
        ->assertInertia(fn (Assert $screen) => $screen->where('session.templates', []));
});

it('tells the editor when the chosen template is not in the current theme', function (): void {
    $declared = [['slug' => 'full-width', 'label' => 'Full width']];

    expect(themeTemplateChoice($declared, 'full-width'))->toEqual(['options' => [['value' => 'full-width', 'label' => 'Full width']], 'missing' => null])
        ->and(themeTemplateChoice($declared, '')['missing'])->toBeNull()
        ->and(themeTemplateChoice($declared, 'landing'))->toEqual([
            'options' => [['value' => 'full-width', 'label' => 'Full width'], ['value' => 'landing', 'label' => 'landing (not in this theme)']],
            'missing' => 'landing',
        ])
        ->and(themeTemplateChoice([], 'landing')['missing'])->toBe('landing')
        ->and(uiComponent('components/editor/rail/publish-panel.tsx'))
        ->toContain('emptyLabel="Default"')
        ->toContain("isn't in the current theme")
        ->not->toContain('<select');
});

it('writes nothing when someone who may not edit the page chooses a template', function (): void {
    $about = lifecycleDraft($this->editor, 'About', 'page');
    $stranger = User::factory()->create()->assignRole('author');
    $revisions = ContentRevision::query()->where('content_id', $about->id)->count();

    expect(fn () => docSave($about, $stranger, docWith(docText('Mine now.')), [BuiltInFields::TEMPLATE => 'full-width']))
        ->toThrow(AuthorizationException::class)
        ->and(ContentRevision::query()->where('content_id', $about->id)->count())->toBe($revisions);
});
