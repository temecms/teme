<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\ChangedLanguage;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeContentLanguage;
use App\Domain\Contents\Operations\Input\ChangeContentLanguageInput;
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

function clChange(User $actor, string $id, string $language, ?string $expected = null): ChangedLanguage
{
    $expected ??= (string) Content::query()->whereKey($id)->value('working_revision_id');

    return app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($id, $language, $expected, (string) $actor->id));
}

/**
 * The item's row and its address rows, to compare before and after a refusal.
 *
 * @return array<string, mixed>
 */
function clState(string $id): array
{
    return [
        'row' => (array) DB::table('contents')->where('id', $id)->first(['language', 'slug', 'translation_group', 'working_revision_id']),
        'paths' => DB::table('paths')->where('target_type', 'content')->where('target_id', $id)->orderBy('path')->get(['path', 'is_current'])->map(fn ($row): array => (array) $row)->all(),
    ];
}

it('moves a published item to French: its address takes the prefix, the old one redirects, its search speaks French', function (): void {
    clChange($this->site->editor, $this->site->alone, 'fr');

    $paths = DB::table('paths')->where('target_type', 'content')->where('target_id', $this->site->alone)->pluck('is_current', 'path')->all();

    expect(DB::table('contents')->where('id', $this->site->alone)->value('language'))->toBe('fr')
        ->and($paths)->toBe(['alone' => false, 'fr/alone' => true])
        ->and(DB::table('contents')->where('id', $this->site->alone)->value('search_config'))->toBe('french')
        ->and(addrLocation(addrGet('/alone/')->assertStatus(301)))->toBe('/fr/alone/');
});

it('refuses a language its group already has', function (): void {
    $before = clState($this->site->postEs);

    expect(fn () => clChange($this->site->editor, $this->site->postEs, 'fr'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['This post already has a French version: “Bonjour”.']);
    });
    expect(clState($this->site->postEs))->toBe($before);
});

it('maps its terms to their French versions, and refuses naming a term with none', function (): void {
    $item = Content::query()->findOrFail($this->site->alone);
    docSave($item, $this->site->editor, docWith(docText('Alone')), terms: [$this->site->news]);
    listsPublish($item, $this->site->editor);

    clChange($this->site->editor, $this->site->alone, 'fr');
    $item->refresh();

    expect(DB::table('content_revisions')->where('id', $item->working_revision_id)->value('terms'))->toBe(json_encode([$this->site->newsFr]))
        ->and(DB::table('content_terms')->where('content_id', $item->id)->pluck('term_id')->unique()->values()->all())->toBe([$this->site->newsFr]);

    $sport = typesTerm($this->site->editor, 'category', 'Sport');
    $other = LanguageSite::version($this->site->editor, 'Match', null, null, [$sport->id], publish: true);
    $before = clState($other);

    expect(fn () => clChange($this->site->editor, $other, 'fr'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['terms'])->toBe(['Its category “Sport” has no French version. File it under a French one first.']);
    });
    expect(clState($other))->toBe($before);
});

it('keeps its slug when free in the new language, and otherwise takes the next and says so', function (): void {
    $kept = clChange($this->site->editor, $this->site->alone, 'fr');
    expect([$kept->slug, $kept->previousSlug, $kept->message()])->toBe(['alone', null, 'Moved to French.']);

    $hello = LanguageSite::version($this->site->editor, 'Bonjour', null, null, [], publish: true);
    $taken = clChange($this->site->editor, $hello, 'fr');

    expect([$taken->slug, $taken->previousSlug])->toBe(['bonjour-2', 'bonjour'])
        ->and($taken->message())->toBe('Moved to French. Its address is now /fr/bonjour-2/, because French already has bonjour.');
});

it('refuses a change made on an older revision', function (): void {
    $item = Content::query()->findOrFail($this->site->alone);
    $old = (string) $item->working_revision_id;
    docSave($item, $this->site->editor, docWith(docText('Newer')));
    $before = clState($this->site->alone);

    expect(fn () => clChange($this->site->editor, $this->site->alone, 'fr', $old))->toThrow(StaleRevisionException::class);
    expect(clState($this->site->alone))->toBe($before);
});

it('refuses to move a page that has a parent or pages under it to another language', function (): void {
    $team = LanguageSite::version($this->site->editor, 'Team', null, null, [], publish: true, type: 'page');
    DB::table('contents')->where('id', $team)->update(['parent_id' => $this->site->page]);

    foreach ([$team, $this->site->page] as $page) {
        $before = clState($page);

        expect(fn () => clChange($this->site->editor, $page, 'es'))->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['language'])->toBe(['Move it to the top, and move the pages under it, first.']);
        });
        expect(clState($page))->toBe($before);
    }
});

it('writes nothing when the person may not edit the item', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $before = clState($this->site->alone);

    expect(fn () => clChange($author, $this->site->alone, 'fr'))->toThrow(AuthorizationException::class);
    expect(clState($this->site->alone))->toBe($before);
});

it('refuses a move whose new address another item holds, and writes nothing', function (): void {
    $contact = LanguageSite::version($this->site->editor, 'Contact', 'fr', null, [], publish: true);
    LanguageSite::version($this->site->editor, 'Contact', null, null, [], publish: true, type: 'page');
    $before = clState($contact);

    expect(fn () => clChange($this->site->editor, $contact, 'en-GB'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['language'])->toBe(['The address /contact/ is used by the page "Contact".']);
    });
    expect(clState($contact))->toBe($before);
});

it('refuses to move the home page or the posts page to another language, and writes nothing', function (): void {
    $blog = LanguageSite::version($this->site->editor, 'Blog', null, null, [], publish: true, type: 'page');
    addrChange($this->site->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $this->site->page, 'postsPageId' => $blog]);

    foreach ([[$this->site->page, 'This is your home page. Choose another in Settings › Reading first.'], [$blog, 'This is your posts page. Choose another in Settings › Reading first.']] as [$page, $words]) {
        $before = clState($page);

        expect(fn () => clChange($this->site->editor, $page, 'es'))->toThrow(function (ValidationException $refusal) use ($words): void {
            expect($refusal->errors()['language'])->toBe([$words]);
        });
        expect(clState($page))->toBe($before);
    }
});
