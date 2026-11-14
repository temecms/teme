<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\ChangeContentLanguage;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ChangeContentLanguageInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\LinkTranslationsInput;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\Input\UnlinkTranslationInput;
use App\Domain\Contents\Operations\LinkTranslations;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\Operations\UnlinkTranslation;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Languages\Operations\ChangeTranslatedTypes;
use App\Domain\Languages\Operations\Input\ChangeTranslatedTypesInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
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
 * The site's languages as a fresh request reads them.
 */
function ttLanguages(): SiteLanguages
{
    app()->forgetScopedInstances();
    app()->forgetInstance(SiteLanguages::class);

    return app(SiteLanguages::class);
}

function ttVersion(): int
{
    return (int) DB::table('settings')->where('group_key', SiteLanguages::GROUP)->value('version');
}

/**
 * @param  list<string>  $types
 * @param  list<string>  $taxonomies
 */
function ttChange(User $actor, array $types, array $taxonomies = [], ?int $version = null): void
{
    app(ChangeTranslatedTypes::class)->execute(new ChangeTranslatedTypesInput($types, $taxonomies, $version ?? ttVersion(), (string) $actor->id));
    ttLanguages();
}

/**
 * What a refused change must leave as it was.
 *
 * @return array<string, mixed>
 */
function ttWritten(): array
{
    return [
        'settings' => (array) DB::table('settings')->where('group_key', SiteLanguages::GROUP)->first(),
        'changes' => DB::table('settings_changes')->count(),
        'contents' => DB::table('contents')->orderBy('id')->get(['id', 'language', 'translation_group', 'working_revision_id'])->map(fn ($row): array => (array) $row)->all(),
        'terms' => DB::table('terms')->orderBy('id')->get(['id', 'language', 'translation_group'])->map(fn ($row): array => (array) $row)->all(),
        'paths' => DB::table('paths')->orderBy('path')->pluck('target_id', 'path')->all(),
    ];
}

/**
 * Takes the pages out of every language but the default: À propos deleted
 * for good, About unlinked, so pages may stop being translated.
 */
function ttPagesAlone(LanguageSite $site): void
{
    pageTrash(Content::query()->findOrFail($site->pageFr), $site->editor);
    app(DeleteContentPermanently::class)->execute(new ContentActionInput($site->pageFr, (string) $site->editor->id));
    app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($site->page, (string) $site->editor->id));
}

it('translates every content type and taxonomy until the owner says otherwise', function (): void {
    $languages = ttLanguages();

    expect(collect(app(ContentTypeRegistry::class)->all())->every(fn (ContentType $type): bool => $languages->translates($type->key)))->toBeTrue()
        ->and(collect(app(TaxonomyRegistry::class)->all())->every(fn (Taxonomy $taxonomy): bool => $languages->translatesTaxonomy($taxonomy->key)))->toBeTrue();
});

it('stops translating a type with no items in other languages, moving no address', function (): void {
    ttPagesAlone($this->site);
    $paths = DB::table('paths')->orderBy('path')->get(['path', 'target_id', 'is_current'])->map(fn ($row): array => (array) $row)->all();

    ttChange($this->site->admin, ['page']);

    expect(ttLanguages()->translates('page'))->toBeFalse()
        ->and(ttLanguages()->translates('post'))->toBeTrue()
        ->and(DB::table('paths')->orderBy('path')->get(['path', 'target_id', 'is_current'])->map(fn ($row): array => (array) $row)->all())->toBe($paths);
});

it('refuses to stop translating a type while items of it are in another language or linked as translations, counting them', function (): void {
    pageTrash(Content::query()->findOrFail($this->site->postEs), $this->site->editor);
    $before = ttWritten();

    expect(fn () => ttChange($this->site->admin, ['post']))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['untranslated_types'])->toBe(['3 posts are in other languages or linked as translations. Move them to English (United Kingdom) and unlink them, or delete them, before posts stop being translated.']);
    });
    expect(ttWritten())->toBe($before);

    // One: the French page, the English one unlinked.
    app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($this->site->page, (string) $this->site->editor->id));

    expect(fn () => ttChange($this->site->admin, ['page']))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['untranslated_types'])->toBe(['1 page is in other languages or linked as translations. Move it to English (United Kingdom) and unlink it, or delete it, before pages stop being translated.']);
    });
});

it('refuses to stop translating a taxonomy while terms of it are in another language or linked, counting them', function (): void {
    $before = ttWritten();

    expect(fn () => ttChange($this->site->admin, [], ['category']))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['untranslated_taxonomies'])->toBe(['2 categories are in other languages or linked as translations. Move them to English (United Kingdom) and unlink them, or delete them, before categories stop being translated.']);
    });
    expect(ttWritten())->toBe($before);
});

it('refuses a type or a taxonomy the site doesn\'t have', function (array $types, array $taxonomies, string $key): void {
    $before = ttWritten();

    expect(fn () => ttChange($this->site->admin, $types, $taxonomies))->toThrow(function (ValidationException $refusal) use ($key): void {
        expect($refusal->errors()[$key])->toBe(['Choose content types and taxonomies this site has.']);
    });
    expect(ttWritten())->toBe($before);
})->with([
    'a type' => [['film'], [], 'untranslated_types'],
    'a taxonomy' => [[], ['genre'], 'untranslated_taxonomies'],
]);

it('starts translating a type again, moving nothing', function (): void {
    ttPagesAlone($this->site);
    ttChange($this->site->admin, ['page']);
    $paths = DB::table('paths')->orderBy('path')->pluck('target_id', 'path')->all();

    ttChange($this->site->admin, []);

    expect(ttLanguages()->translates('page'))->toBeTrue()
        ->and(DB::table('paths')->orderBy('path')->pluck('target_id', 'path')->all())->toBe($paths);
});

it('refuses a draft in a language, a translation, a link and a language change for a type that isn\'t translated, in these words', function (string $operation): void {
    ttPagesAlone($this->site);
    $team = LanguageSite::version($this->site->editor, 'Team', null, null, [], publish: true, type: 'page');
    ttChange($this->site->admin, ['page']);
    $by = (string) $this->site->editor->id;
    $page = $this->site->page;
    $before = ttWritten();

    $run = match ($operation) {
        'draft' => fn () => app(CreateDraft::class)->execute(new CreateDraftInput(type: 'page', title: 'Équipe', document: ['type' => 'doc', 'content' => []], fields: [], actorId: $by, language: 'fr')),
        'translate' => fn () => app(TranslateContent::class)->execute(new TranslateContentInput($page, 'fr', $by)),
        'link' => fn () => app(LinkTranslations::class)->execute(new LinkTranslationsInput([$page, $team], $by)),
        'change' => fn () => app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($page, 'fr', (string) Content::query()->findOrFail($page)->working_revision_id, $by)),
    };

    expect($run)->toThrow(function (ValidationException $refusal): void {
        expect(collect($refusal->errors())->flatten()->all())->toContain('Pages aren\'t translated on this site. Turn translation on for them in Settings › Languages first.');
    });
    expect(ttWritten())->toBe($before);
})->with(['draft', 'translate', 'link', 'change']);

it('refuses a term in a language for a taxonomy that isn\'t translated', function (): void {
    ttChange($this->site->admin, [], ['tag']);
    $before = ttWritten();

    expect(fn () => app(CreateTerm::class)->execute(new SaveTermInput('tag', 'Locale', null, null, null, (string) $this->site->editor->id, language: 'fr')))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['language'])->toBe(['Tags aren\'t translated on this site, so their terms are shared by every language.']);
        });
    expect(ttWritten())->toBe($before);
});

it('lets an item in any language be filed under a taxonomy that isn\'t translated, and keeps those terms on Translate', function (): void {
    ttChange($this->site->admin, [], ['tag']);
    $local = typesTerm($this->site->editor, 'tag', 'Local');
    app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($this->site->postEs, (string) $this->site->editor->id));

    docSave(Content::query()->findOrFail($this->site->postFr), $this->site->editor, docWith(docText('Bonjour')), terms: [$this->site->newsFr, $local->id]);
    listsPublish(Content::query()->findOrFail($this->site->postFr), $this->site->editor);
    $spanish = app(TranslateContent::class)->execute(new TranslateContentInput($this->site->postFr, 'es', (string) $this->site->editor->id))->content;
    $terms = (array) ContentRevision::query()->findOrFail($spanish->working_revision_id)->terms;

    expect($terms)->toContain($local->id)
        ->and($terms)->not->toContain($this->site->newsFr)
        ->and(DB::table('terms')->where('taxonomy', 'tag')->count())->toBe(1);
});

it('gives the editor of a type that isn\'t translated no languages, no versions and no Translate', function (): void {
    ttPagesAlone($this->site);
    ttChange($this->site->admin, ['page']);

    $session = EditorSession::for($this->site->page, (string) $this->site->editor->id);

    expect($session->languages)->toBe([])
        ->and($session->translations)->toBe([])
        ->and($session->can['translate'])->toBeFalse()
        ->and(EditorSession::for($this->site->post, (string) $this->site->editor->id)->can['translate'])->toBeTrue();
});

it('keeps the addresses of a type that isn\'t translated without a prefix', function (): void {
    ttPagesAlone($this->site);
    $team = LanguageSite::version($this->site->editor, 'Team', null, null, [], publish: true, type: 'page');
    ttChange($this->site->admin, ['page']);

    expect(DB::table('contents')->where('type', 'page')->whereNotNull('language')->count())->toBe(0)
        ->and(addrCurrent('content', $this->site->page))->toBe('about')
        ->and(addrCurrent('content', $team))->toBe('team');
});

it('lists only the default language\'s items in the archive of a taxonomy that isn\'t translated', function (): void {
    ttChange($this->site->admin, [], ['tag']);
    $local = typesTerm($this->site->editor, 'tag', 'Local');

    foreach ([$this->site->post => [$this->site->news, $local->id], $this->site->postFr => [$this->site->newsFr, $local->id]] as $id => $terms) {
        $item = Content::query()->findOrFail($id);
        docSave($item, $this->site->editor, docWith(docText((string) $item->title)), terms: $terms);
        listsPublish($item, $this->site->editor);
    }

    $archive = addrGet('/tag/local/')->assertOk();

    expect($archive->getContent())->toContain('Hello')->not->toContain('Bonjour');
    addrGet('/fr/tag/local/')->assertNotFound();
});

it('refuses a form opened on an older version of the languages', function (): void {
    $version = ttVersion();
    ttChange($this->site->admin, [], ['tag']);

    $this->actingAs($this->site->admin)
        ->from('/admin/settings/languages')
        ->put(route('settings.languages.translated'), ['untranslated_types' => [], 'untranslated_taxonomies' => [], 'expected_version' => $version])
        ->assertSessionHasErrors(['expected_version' => 'Someone changed the languages while you were editing. Reload and try again.']);
});

it('writes nothing when someone without the General settings ability changes what is translated', function (): void {
    $before = ttWritten();

    expect(fn () => ttChange($this->site->editor, [], ['tag']))->toThrow(AuthorizationException::class);
    expect(ttWritten())->toBe($before);
});

it('answers its route for an administrator and refuses an editor', function (): void {
    $this->actingAs($this->site->admin)
        ->from('/admin/settings/languages')
        ->put(route('settings.languages.translated'), ['untranslated_types' => [], 'untranslated_taxonomies' => ['tag'], 'expected_version' => ttVersion()])
        ->assertRedirect('/admin/settings/languages')
        ->assertInertiaFlash('toast.message', 'Saved what is translated.');

    expect(ttLanguages()->translatesTaxonomy('tag'))->toBeFalse();

    $this->actingAs($this->site->editor)
        ->put(route('settings.languages.translated'), ['untranslated_types' => [], 'untranslated_taxonomies' => [], 'expected_version' => ttVersion()])
        ->assertForbidden();
});

it('reads whether a taxonomy is shared from the languages\' row under the lock on Translate, not the request\'s copy', function (): void {
    $local = typesTerm($this->site->editor, 'tag', 'Local');
    $source = lifecycleDraft($this->site->editor, 'Derby');
    docSave($source, $this->site->editor, docWith(docText('Derby')), terms: [$local->id]);
    listsPublish($source, $this->site->editor);

    // The request read the settings while tags were translated; tags stop being translated before the lock.
    expect(app(SiteLanguages::class)->translatesTaxonomy('tag'))->toBeTrue();
    DB::table('settings')->where('group_key', SiteLanguages::GROUP)->update(['overrides' => DB::raw("jsonb_set(overrides, '{untranslated_taxonomies}', '[\"tag\"]')")]);

    $french = app(TranslateContent::class)->execute(new TranslateContentInput($source->id, 'fr', (string) $this->site->editor->id))->content;

    expect((array) ContentRevision::query()->findOrFail($french->working_revision_id)->terms)->toBe([$local->id])
        ->and(DB::table('terms')->where('taxonomy', 'tag')->count())->toBe(1);
});

it('reads whether a taxonomy is shared from the languages\' row under the lock on a move, and writes nothing when it is no longer', function (): void {
    ttChange($this->site->admin, [], ['tag']);
    $local = typesTerm($this->site->editor, 'tag', 'Local');
    $source = lifecycleDraft($this->site->editor, 'Derby');
    docSave($source, $this->site->editor, docWith(docText('Derby')), terms: [$local->id]);
    listsPublish($source, $this->site->editor);

    // The request read the settings while tags were shared; tags are translated again before the lock.
    expect(app(SiteLanguages::class)->translatesTaxonomy('tag'))->toBeFalse();
    DB::table('settings')->where('group_key', SiteLanguages::GROUP)->update(['overrides' => DB::raw("jsonb_set(overrides, '{untranslated_taxonomies}', '[]')")]);
    $before = DB::table('contents')->where('id', $source->id)->first(['language', 'working_revision_id']);

    expect(fn () => app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($source->id, 'fr', (string) $source->refresh()->working_revision_id, (string) $this->site->editor->id)))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['terms'])->toBe(['Its tag “Local” has no French version. File it under a French one first.']);
        });
    expect(DB::table('contents')->where('id', $source->id)->first(['language', 'working_revision_id']))->toEqual($before);
});
