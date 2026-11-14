<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\ArchiveAutosave;
use App\Domain\Contents\Operations\ChangeContentLanguage;
use App\Domain\Contents\Operations\CheckpointDraft;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ArchiveAutosaveInput;
use App\Domain\Contents\Operations\Input\ChangeContentLanguageInput;
use App\Domain\Contents\Operations\Input\CheckpointDraftInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\RestoreRevisionInput;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\RestoreRevision;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * A new draft through CreateDraft, in a language given as its tag.
 *
 * @param  list<string>  $terms
 */
function tplDraft(User $actor, string $title, ?string $language, string $type = 'post', array $terms = []): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput($type, $title, docWith(docText($title)), [], (string) $actor->id, $terms, $language));
}

/**
 * A new term through CreateTerm, in a language given as its tag.
 */
function tplTerm(User $actor, string $name, ?string $language, ?string $slug = null): Term
{
    return app(CreateTerm::class)->execute(new SaveTermInput('category', $name, $slug, null, null, (string) $actor->id, language: $language));
}

it('creates a draft in another site language, storing its tag', function (): void {
    $draft = tplDraft($this->site->editor, 'Salut le monde', 'fr');

    expect(DB::table('contents')->where('id', $draft->id)->value('language'))->toBe('fr');
});

it('creates a default-language draft storing no language', function (): void {
    $named = tplDraft($this->site->editor, 'Named', 'en-GB');
    $unnamed = tplDraft($this->site->editor, 'Unnamed', null);

    expect(DB::table('contents')->whereIn('id', [$named->id, $unnamed->id])->pluck('language')->all())->toBe([null, null]);
});

it('refuses a language the site does not have', function (): void {
    $before = DB::table('contents')->count();

    expect(fn () => tplDraft($this->site->editor, 'Hallo', 'de'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['German isn\'t one of your site\'s languages.']);
    });

    expect(DB::table('contents')->count())->toBe($before);
});

it('allows one item per language of a type with one item only', function (): void {
    ctCreate($this->site->admin, ['key' => 'about', 'singular' => 'About page', 'plural' => 'About pages', 'segment' => 'about', 'icon' => 'info', 'single' => true, 'archive' => false, 'taxonomies' => [], 'supports' => ['seo'], 'comments' => null]);

    tplDraft($this->site->admin, 'About us', null, 'about');
    tplDraft($this->site->admin, 'À propos', 'fr', 'about');

    expect(fn () => tplDraft($this->site->admin, 'Encore', 'fr', 'about'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['type'])->toContain('There can be only one About page, and it already exists.');
    });

    expect(fn () => tplDraft($this->site->admin, 'Again', null, 'about'))->toThrow(ValidationException::class);
    expect(Content::withTrashed()->where('type', 'about')->pluck('language')->all())->toEqualCanonicalizing([null, 'fr']);
});

it('carries the revision a version was translated from through every save, checkpoint and restore', function (): void {
    $version = Content::query()->findOrFail($this->site->postFr);
    $source = (string) Content::query()->whereKey($this->site->post)->value('published_revision_id');
    $first = (string) $version->working_revision_id;
    DB::table('content_revisions')->where('id', $first)->update(['source_revision_id' => $source]);
    $editor = $this->site->editor;
    $sourceOfWorking = fn (): ?string => ContentRevision::query()->whereKey($version->refresh()->working_revision_id)->value('source_revision_id');

    docSave($version, $editor, docWith(docText('Bonjour encore')));
    expect($sourceOfWorking())->toBe($source);

    app(SaveAutosave::class)->execute(new SaveAutosaveInput($version->id, 'Bonjour', docWith(docText('Bonjour, autosaved')), [], (array) ContentRevision::query()->whereKey($version->refresh()->working_revision_id)->value('terms'), $version->working_revision_id, (string) $editor->id));
    app(CheckpointDraft::class)->execute(new CheckpointDraftInput($version->id, (string) $editor->id));
    expect($sourceOfWorking())->toBe($source);

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($version->id, (string) $version->refresh()->working_revision_id, (string) $editor->id, title: 'Bonjour, renamed'));
    expect($sourceOfWorking())->toBe($source);

    // An abandoned autosave archived as a checkpoint keeps it too.
    edSave($version->id, $editor, docWith(docText('Bonjour, abandoned')));
    DB::table('content_autosaves')->update(['saved_at' => now()->subDays(config('editor.autosave_ttl_days') + 1)]);
    $archived = scElevated(fn (): ?string => app(ArchiveAutosave::class)->execute(new ArchiveAutosaveInput((string) DB::table('content_autosaves')->value('id'))));
    expect(ContentRevision::query()->whereKey($archived)->value('source_revision_id'))->toBe($source);

    // An older revision restored brings back the source it was translated from.
    DB::table('content_revisions')->where('id', $first)->update(['source_revision_id' => null]);
    app(RestoreRevision::class)->execute(new RestoreRevisionInput($version->id, $first, (string) $editor->id));
    expect($sourceOfWorking())->toBeNull();
});

it('refuses a term of another language on an item\'s draft, naming it', function (): void {
    $version = Content::query()->findOrFail($this->site->postFr);

    expect(fn () => docSave($version, $this->site->editor, docWith(docText('Bonjour')), terms: [$this->site->newsFr, $this->site->news]))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['terms'])->toBe(['“News” is in English (United Kingdom). Choose a French category instead.']);
        });

    docSave($version, $this->site->editor, docWith(docText('Bonjour')), terms: [$this->site->newsFr]);

    expect(fn () => tplDraft($this->site->editor, 'Salut', 'fr', terms: [$this->site->news]))->toThrow(ValidationException::class)
        ->and(tplDraft($this->site->editor, 'Salut', 'fr', terms: [$this->site->newsFr])->language)->toBe('fr')
        ->and(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($version->id, (string) $version->refresh()->working_revision_id, (string) $this->site->editor->id, terms: [$this->site->news])))->toThrow(ValidationException::class);
});

it('creates a term in a language, its slug unique within it', function (): void {
    $english = tplTerm($this->site->editor, 'Sport', null, 'sport');
    $french = tplTerm($this->site->editor, 'Sport', 'fr', 'sport');
    $again = tplTerm($this->site->editor, 'Sport encore', 'fr', 'sport');

    expect([$english->slug, $english->language])->toBe(['sport', null])
        ->and([$french->slug, $french->language])->toBe(['sport', 'fr'])
        ->and([$again->slug, $again->language])->toBe(['sport-2', 'fr']);

    expect(fn () => tplTerm($this->site->editor, 'Sport', 'de'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['German isn\'t one of your site\'s languages.']);
    });
});

it('changes a published item\'s slug within its language only', function (): void {
    $version = Content::query()->findOrFail($this->site->postFr);
    $details = fn (string $slug) => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($version->id, (string) $version->refresh()->working_revision_id, (string) $this->site->editor->id, slug: $slug));

    // The English post's slug is free in French.
    $details('hello');
    expect($version->refresh()->slug)->toBe('hello');

    LanguageSite::version($this->site->editor, 'Coucou', 'fr', null, [], publish: true);

    expect(fn () => $details('coucou'))->toThrow(ValidationException::class);
    expect($version->refresh()->slug)->toBe('hello');
});

it('writes nothing when someone may not create the term', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $before = DB::table('terms')->count();

    expect(fn () => tplTerm($author, 'Sport', 'fr'))->toThrow(AuthorizationException::class);
    expect(DB::table('terms')->count())->toBe($before);
});

/**
 * The terms table, to compare before and after a refusal.
 *
 * @return list<array<string, mixed>>
 */
function tplTerms(): array
{
    return DB::table('terms')->orderBy('id')->get(['id', 'parent_id', 'language', 'slug'])->map(fn ($row): array => (array) $row)->all();
}

it('refuses a new term under a parent in another language, and writes nothing', function (): void {
    $before = tplTerms();

    expect(fn () => app(CreateTerm::class)->execute(new SaveTermInput('category', 'Football', null, $this->site->news, null, (string) $this->site->editor->id, language: 'fr')))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['parent'])->toBe(['The parent must be another French category.']);
        });
    expect(tplTerms())->toBe($before);
});

it('refuses moving a term under a parent in another language, and writes nothing', function (): void {
    $football = tplTerm($this->site->editor, 'Football', 'fr');
    $before = tplTerms();

    expect(fn () => app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Football', 'football', $this->site->news, null, (string) $this->site->editor->id, termId: $football->id)))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['parent'])->toBe(['The parent must be another French category.']);
        });
    expect(tplTerms())->toBe($before);
});

it('files a new term under a parent in its own language', function (): void {
    $football = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Football', null, $this->site->newsFr, null, (string) $this->site->editor->id, language: 'fr'));

    expect([$football->language, $football->parent_id])->toBe(['fr', $this->site->newsFr]);
});

it('refuses to translate or move an item of a type with one item only into a language that has its one, and writes nothing', function (string $operation): void {
    ctCreate($this->site->admin, ['key' => 'about', 'singular' => 'About page', 'plural' => 'About pages', 'segment' => 'about', 'icon' => 'info', 'single' => true, 'archive' => false, 'taxonomies' => [], 'supports' => ['seo'], 'comments' => null]);
    $english = tplDraft($this->site->admin, 'About us', null, 'about');
    tplDraft($this->site->admin, 'À propos', 'fr', 'about');
    $before = DB::table('contents')->where('type', 'about')->orderBy('id')->get(['id', 'language', 'translation_group', 'working_revision_id'])->map(fn ($row): array => (array) $row)->all();

    expect(fn () => match ($operation) {
        'translate' => app(TranslateContent::class)->execute(new TranslateContentInput($english->id, 'fr', (string) $this->site->admin->id)),
        'move' => app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($english->id, 'fr', (string) $english->working_revision_id, (string) $this->site->admin->id)),
    })->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['There can be only one About page, and it already exists.']);
    });
    expect(DB::table('contents')->where('type', 'about')->orderBy('id')->get(['id', 'language', 'translation_group', 'working_revision_id'])->map(fn ($row): array => (array) $row)->all())->toBe($before);
})->with(['translate', 'move']);
