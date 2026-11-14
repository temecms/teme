<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeContentLanguage;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ChangeContentLanguageInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\LinkTranslationsInput;
use App\Domain\Contents\Operations\Input\MarkTranslationUpToDateInput;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\Input\UnlinkTranslationInput;
use App\Domain\Contents\Operations\LinkTranslations;
use App\Domain\Contents\Operations\MarkTranslationUpToDate;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\Operations\UnlinkTranslation;
use App\Domain\Languages\Operations\AddSiteLanguage;
use App\Domain\Languages\Operations\ChangeSiteLanguage;
use App\Domain\Languages\Operations\ChangeTranslatedTypes;
use App\Domain\Languages\Operations\Input\AddSiteLanguageInput;
use App\Domain\Languages\Operations\Input\ChangeSiteLanguageInput;
use App\Domain\Languages\Operations\Input\ChangeTranslatedTypesInput;
use App\Domain\Languages\Operations\Input\RemoveSiteLanguageInput;
use App\Domain\Languages\Operations\RemoveSiteLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\LanguageColumns;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('finds a row that stores the default language\'s tag', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $post = lifecycleDraft($editor, 'Hello');
    lifecycleDraft($editor, 'Alone');
    DB::table('contents')->where('id', $post->id)->update(['language' => 'en']);

    expect(LanguageColumns::defaultTagged())->toBe([$post->id]);
});

it('finds none after the fixture is built', function (): void {
    LanguageSite::build();

    expect(LanguageColumns::defaultTagged())->toBe([]);
});

it('never writes the default language\'s tag through any of this release\'s Operations', function (): void {
    $site = LanguageSite::build();
    $by = (string) $site->editor->id;
    $admin = (string) $site->admin->id;
    $version = fn (): int => (int) DB::table('settings')->where('group_key', SiteLanguages::GROUP)->value('version');
    $working = fn (string $id): string => (string) Content::query()->whereKey($id)->value('working_revision_id');

    // Each names the default language by its tag wherever it takes one.
    $draft = app(CreateDraft::class)->execute(new CreateDraftInput(type: 'post', title: 'Tagged', document: ['type' => 'doc', 'content' => []], fields: [], actorId: $by, language: 'en-GB'));
    app(CreateTerm::class)->execute(new SaveTermInput('category', 'Tagged', null, null, null, $by, language: 'en-GB'));
    $seul = LanguageSite::version($site->editor, 'Seul', 'fr', null, [], publish: true);
    $english = app(TranslateContent::class)->execute(new TranslateContentInput($seul, 'en-GB', $by))->content;
    app(MarkTranslationUpToDate::class)->execute(new MarkTranslationUpToDateInput($english->id, $working($english->id), $by));
    app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($english->id, $by));
    app(LinkTranslations::class)->execute(new LinkTranslationsInput([$english->id, $seul], $by));
    app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($site->alone, 'fr', $working($site->alone), $by));
    app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($site->alone, 'en-GB', $working($site->alone), $by));
    app(AddSiteLanguage::class)->execute(new AddSiteLanguageInput('de', 'de', true, $version(), $admin));
    app(ChangeSiteLanguage::class)->execute(new ChangeSiteLanguageInput('de', 'deutsch', false, $version(), $admin));
    app(RemoveSiteLanguage::class)->execute(new RemoveSiteLanguageInput('de', $version(), $admin));
    app(ChangeTranslatedTypes::class)->execute(new ChangeTranslatedTypesInput([], ['tag'], $version(), $admin));

    expect(LanguageColumns::defaultTagged())->toBe([]);
});
