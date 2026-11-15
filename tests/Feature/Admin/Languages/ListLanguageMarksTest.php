<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Domain\Preferences\Queries\PreferencesFor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * The posts list's rows as this request gives them, by title.
 *
 * @return array<string, array<string, mixed>>
 */
function marksRows(LanguageSite $site, ?string $language = null): array
{
    $rows = app(ContentList::class)->page(new ContentListRequest(type: 'post', viewerId: $site->editor->id, language: $language))['rows'];

    return collect($rows)->keyBy('title')->all();
}

it('gives each row the languages of its group, a row alone only its own', function (): void {
    $rows = marksRows($this->site);

    expect($rows['Hello']['versions'])->toEqual(['en-GB' => 'there', 'fr' => 'there', 'es' => 'there'])
        ->and($rows['Bonjour']['versions'])->toEqual($rows['Hello']['versions'])
        ->and($rows['Alone']['versions'])->toEqual(['en-GB' => 'there']);
});

it('marks a version whose source published a newer revision as out of date', function (): void {
    $source = Content::query()->findOrFail($this->site->alone);
    app(TranslateContent::class)->execute(new TranslateContentInput($this->site->alone, 'fr', (string) $this->site->editor->id));
    $this->travel(1)->minutes();
    docSave($source, $this->site->editor, docWith(docText('Alone, rewritten')));
    listsPublish($source, $this->site->editor);

    expect(marksRows($this->site)['Alone']['versions'])->toEqual(['en-GB' => 'there', 'fr' => 'outOfDate']);
});

it('reads every row\'s versions in one statement, however many rows the page holds', function (): void {
    foreach (range(1, 6) as $n) {
        LanguageSite::version($this->site->editor, "Extra {$n}", $n % 2 === 0 ? 'fr' : null, null, [], publish: false);
    }

    $statements = 0;
    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'translation_group = ANY')) {
            $statements++;
        }
    });

    $rows = marksRows($this->site);

    expect($rows)->toHaveCount(10)
        ->and($statements)->toBe(1);
});

it('gives no versions on a site with one language, and reads none', function (): void {
    DB::table('contents')->update(['language' => null, 'translation_group' => null]);
    DB::table('terms')->update(['language' => null, 'translation_group' => null]);
    Languages::set([]);
    $statements = 0;
    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'translation_group = ANY')) {
            $statements++;
        }
    });

    expect(collect(marksRows($this->site))->pluck('versions')->unique()->all())->toBe([null])
        ->and($statements)->toBe(0);
});

it('lists the items missing a version in a language', function (): void {
    expect(array_keys(marksRows($this->site, 'missing:fr')))->toBe(['Alone'])
        ->and(array_keys(marksRows($this->site, 'missing:es')))->toBe(['Alone'])
        ->and(array_keys(marksRows($this->site, 'missing:en-GB')))->toBe([]);

    LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: false);

    expect(array_keys(marksRows($this->site, 'missing:en-GB')))->toBe(['Seul'])
        ->and(marksRows($this->site, 'missing:it'))->toHaveCount(5);
});

it('gives the posts list the site\'s languages for its marks and filter, in order', function (): void {
    $this->actingAs($this->site->editor)->get('/admin/posts')
        ->assertInertia(fn (Assert $page) => $page->where('languages', [
            ['tag' => 'en-GB', 'name' => 'English (United Kingdom)'],
            ['tag' => 'fr', 'name' => 'French'],
            ['tag' => 'es', 'name' => 'Spanish'],
        ]));
});

it('gives the categories list each term\'s versions', function (): void {
    $this->actingAs($this->site->admin)->get('/admin/categories')
        ->assertInertia(fn (Assert $page) => $page
            ->where('languages.1.tag', 'fr')
            ->where("versions.{$this->site->news}", ['en-GB' => 'there', 'fr' => 'there'])
            ->where("versions.{$this->site->newsFr}", ['en-GB' => 'there', 'fr' => 'there']));
});

it('offers the Languages column in the chooser, never on until someone turns it on', function (): void {
    expect(PreferencesFor::user($this->site->editor)['lists.post.columns'])->not->toContain('languages');

    lscrPrefer($this->site->editor, ['lists.post.columns' => ['languages', 'author', 'date']]);

    expect(PreferencesFor::user($this->site->editor)['lists.post.columns'])->toBe(['languages', 'author', 'date']);
});
