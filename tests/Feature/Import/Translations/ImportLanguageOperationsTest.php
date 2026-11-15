<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\Operations\ImportSiteLanguages;
use App\Domain\Import\Operations\ImportTranslatedSettings;
use App\Domain\Import\Operations\Input\ImportSiteLanguagesInput;
use App\Domain\Import\Operations\Input\ImportTranslatedSettingsInput;
use App\Domain\Import\Operations\Input\LinkImportedTranslationsInput;
use App\Domain\Import\Operations\LinkImportedTranslations;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\PolylangSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    $this->site = PolylangSite::serve();
    $this->source = importSource();
    $this->editor = User::factory()->create()->assignRole('editor');
});

/**
 * The translation group of the items WordPress posts became.
 *
 * @param  list<int>  $ids
 *
 * @return list<string|null>
 */
function ploGroups(string $sourceId, array $ids): array
{
    return array_map(fn (int $id): ?string => Content::query()->whereKey(app(ImportMap::class)->temeId($sourceId, SourceRef::of('post', $id)))->value('translation_group'), $ids);
}

it('adds no language and translates nothing differently for someone who may not import', function (): void {
    $before = app(SiteLanguages::class)->others();

    expect(fn () => app(ImportSiteLanguages::class)->execute(new ImportSiteLanguagesInput([['language' => 'es', 'prefix' => 'es']], ['page'], [], (string) $this->editor->id)))->toThrow(AuthorizationException::class);

    app()->forgetInstance(SiteLanguages::class);

    expect(app(SiteLanguages::class)->others())->toBe($before)
        ->and(app(SiteLanguages::class)->translates('page'))->toBeTrue();
});

it('links no translations for someone who may not import', function (): void {
    $this->site->import($this->source);
    $twenty = ploGroups($this->source->id, [24, 25]);

    expect(fn () => app(LinkImportedTranslations::class)->execute(new LinkImportedTranslationsInput($this->source->id, 'content', [['24', '25']], (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(ploGroups($this->source->id, [24, 25]))->toBe($twenty);
});

it('skips a group with two items in one language, and one of items of different types, linking neither', function (): void {
    $this->site->import($this->source);
    $before = ploGroups($this->source->id, [21, 25, 22, 42]);

    $written = scElevated(fn (): array => app(LinkImportedTranslations::class)->execute(new LinkImportedTranslationsInput($this->source->id, 'content', [['21', '25'], ['24', '41']], null)));

    expect($written['linked'])->toBe(0)
        ->and(array_column($written['skipped'], 'ids'))->toBe([['21', '25'], ['24', '41']])
        ->and(ploGroups($this->source->id, [21, 25, 22, 42]))->toBe($before)
        ->and(ploGroups($this->source->id, [24]))->toBe([null]);
});

it('writes no words in another language for someone who may not import', function (): void {
    $this->site->import($this->source);
    $before = SettingsGroupRecord::query()->find('general')?->translations;

    expect(fn () => app(ImportTranslatedSettings::class)->execute(new ImportTranslatedSettingsInput('general', 'es', ['tagline' => 'Otra cosa'], (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(SettingsGroupRecord::query()->find('general')?->translations)->toBe($before);
});

it('keeps a word this site already has in that language', function (): void {
    $this->site->import($this->source);

    $written = scElevated(fn (): array => app(ImportTranslatedSettings::class)->execute(new ImportTranslatedSettingsInput('general', 'es', ['tagline' => 'Otra cosa', 'site_title' => 'La Gaceta del Puerto'], null)));

    expect($written)->toBe(['written' => ['site_title'], 'kept' => ['tagline']])
        ->and(SettingsGroupRecord::query()->find('general')?->translations['es']['tagline'] ?? null)->toBe('Noticias del muelle');
});

it('says why it refuses in the site\'s language', function (Closure $refused, string $words): void {
    // The words end in a full stop, which addLines() would read as a nested key.
    app('translator')->setLoaded(['*' => ['*' => [app()->getLocale() => [$words => 'Translated: '.$words]]]]);

    try {
        scElevated($refused);
        $said = null;
    } catch (ValidationException $refusal) {
        $said = Arr::first(Arr::flatten($refusal->errors()));
    }

    expect($said)->toBe('Translated: '.str_replace(':group', 'nowhere', $words));
})->with([
    'a kind it doesn’t link' => [fn () => app(LinkImportedTranslations::class)->execute(new LinkImportedTranslationsInput('source', 'media', [['1', '2']], null)), 'Link posts and pages, or terms.'],
    'too many groups' => [fn () => app(LinkImportedTranslations::class)->execute(new LinkImportedTranslationsInput('source', 'content', array_fill(0, 501, ['1', '2']), null)), 'At most 500 groups at a time.'],
    'a group Teme hasn’t' => [fn () => app(ImportTranslatedSettings::class)->execute(new ImportTranslatedSettingsInput('nowhere', 'es', ['a' => 'b'], null)), 'Teme has no settings group [:group].'],
]);
