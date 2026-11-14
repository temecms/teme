<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\UnlinkTranslationInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UnlinkTranslation;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Contents\Tree\TreePlace;
use App\Domain\Export\Kinds\ContentsKind;
use App\Domain\Export\Kinds\TermsKind;
use App\Domain\Import\Operations\Input\PlaceImportedPageInput;
use App\Domain\Import\Operations\PlaceImportedPage;
use App\Domain\Import\PlacedPage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\Tree\ImportedPages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * The item's row, its address rows and its revisions, to compare before and after a refusal.
 *
 * @return array<string, mixed>
 */
function tsState(string $id): array
{
    return [
        'row' => (array) DB::table('contents')->where('id', $id)->first(['parent_id', 'position', 'slug', 'working_revision_id']),
        'paths' => DB::table('paths')->where('target_type', 'content')->where('target_id', $id)->orderBy('path')->pluck('is_current', 'path')->all(),
        'revisions' => DB::table('content_revisions')->where('content_id', $id)->count(),
    ];
}

it('gives the editor the item\'s language, the site\'s languages and its group\'s versions with their state', function (): void {
    $site = LanguageSite::build();
    $session = EditorSession::for($site->postFr, (string) $site->editor->id);
    $versions = collect($session->translations)->keyBy('language');

    expect($session->language)->toBe('fr')
        ->and($session->languages)->toBe([
            ['tag' => 'en-GB', 'name' => 'English (United Kingdom)', 'shown' => true],
            ['tag' => 'fr', 'name' => 'French', 'shown' => true],
            ['tag' => 'es', 'name' => 'Spanish', 'shown' => false],
        ])
        ->and($versions->keys()->all())->toBe(['en-GB', 'es', 'fr'])
        ->and($versions['en-GB'])->toMatchArray(['id' => $site->post, 'title' => 'Hello', 'status' => 'published', 'current' => false, 'outOfDate' => false, 'editUrl' => '/admin/content/'.$site->post.'/edit'])
        ->and($versions['fr'])->toMatchArray(['id' => $site->postFr, 'title' => 'Bonjour', 'current' => true])
        ->and($session->can['translate'])->toBeTrue()
        ->and(EditorSession::for($site->post, (string) $site->editor->id)->language)->toBe('en-GB');
});

it('gives a single-language site\'s editor no languages and no versions', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $post = lifecycleDraft($editor, 'Hello');

    $session = EditorSession::for($post->id, (string) $editor->id);

    expect($session->languages)->toBe([])
        ->and($session->translations)->toBe([])
        ->and($session->can['translate'])->toBeFalse();
});

it('never gives the editor a group\'s ID', function (): void {
    $site = LanguageSite::build();

    expect(json_encode(EditorSession::for($site->post, (string) $site->editor->id)))->not->toContain($site->postGroup);
});

it('names a version only to someone who may open it', function (): void {
    $site = LanguageSite::build();
    app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($site->postEs, (string) $site->editor->id));
    $author = User::factory()->create()->assignRole('author');
    $mine = LanguageSite::version($author, 'Mío', 'es', $site->postGroup, [], publish: false);

    $versions = collect(EditorSession::for($mine, (string) $author->id)->translations)->keyBy('language');

    expect($versions['es'])->toMatchArray(['id' => $mine, 'title' => 'Mío', 'current' => true])
        ->and($versions['en-GB'])->toMatchArray(['id' => null, 'title' => null, 'editUrl' => null, 'status' => 'published'])
        ->and($versions['fr'])->toMatchArray(['id' => null, 'title' => null, 'editUrl' => null, 'status' => 'published'])
        ->and(json_encode($versions->all()))->not->toContain('Bonjour')->not->toContain($site->postFr);
});

it('refuses a parent in another language', function (): void {
    $site = LanguageSite::build();
    $equipe = LanguageSite::version($site->editor, 'Équipe', 'fr', null, [], publish: true, type: 'page');
    $team = LanguageSite::version($site->editor, 'Team', null, null, [], publish: true, type: 'page');

    foreach ([[$equipe, $site->page, 'A page\'s parent is in its own language: choose the French “À propos”.'], [$team, $site->pageFr, 'A page\'s parent is in its own language: choose the English (United Kingdom) “About”.']] as [$child, $parent, $words]) {
        $before = tsState($child);
        $item = Content::query()->findOrFail($child);

        expect(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
            contentId: $child,
            expectedRevisionId: (string) $item->working_revision_id,
            actorId: (string) $site->editor->id,
            place: new TreePlace($parent, null),
        )))->toThrow(function (ValidationException $refusal) use ($words): void {
            expect(collect($refusal->errors())->flatten()->all())->toContain($words);
        });
        expect(tsState($child))->toBe($before);
    }
});

it('leaves an imported page under a parent in another language where it is, with its note', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    importFake();
    $source = importSource();
    $editor = User::factory()->create()->assignRole('editor');
    $pages = ImportedPages::import($source, $editor, [10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0]]);
    Languages::set(['fr']);
    DB::table('contents')->where('id', $pages[10]->id)->update(['language' => 'fr']);

    $placed = scElevated(fn (): PlacedPage => app(PlaceImportedPage::class)->execute(new PlaceImportedPageInput($source->id, '11', 0, null)));

    expect($placed->status)->toBe(PlacedPage::LEFT)
        ->and($placed->code)->toBe('page.refused')
        ->and($placed->note)->toBe('“Team” couldn\'t go back under “About”: A page\'s parent is in its own language, and “About” has no English version yet.')
        ->and($pages[11]->refresh()->parent_id)->toBeNull();
});

it('lists one language\'s items when the list is filtered by it, with no total', function (): void {
    $site = LanguageSite::build();

    $french = app(ContentList::class)->page(new ContentListRequest(type: 'post', viewerId: $site->editor->id, language: 'fr'));
    $english = app(ContentList::class)->page(new ContentListRequest(type: 'post', viewerId: $site->editor->id, language: 'en-GB'));

    expect(array_column($french['rows'], 'title'))->toBe(['Bonjour'])
        ->and($french['filtered'])->toBeTrue()
        ->and(array_column($english['rows'], 'title'))->toEqualCanonicalizing(['Hello', 'Alone']);
});

it('shows each row\'s language', function (): void {
    $site = LanguageSite::build();

    $rows = collect(app(ContentList::class)->page(new ContentListRequest(type: 'post', viewerId: $site->editor->id))['rows'])->pluck('language', 'title')->all();

    expect($rows)->toEqualCanonicalizing(['Hello' => 'en-GB', 'Bonjour' => 'fr', 'Hola' => 'es', 'Alone' => 'en-GB']);
});

it('exports each item\'s and each term\'s language and group', function (): void {
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    $site = LanguageSite::build();

    $items = collect(ExportAs::kindLines(app(ContentsKind::class)))->keyBy('id');
    $terms = collect(ExportAs::kindLines(app(TermsKind::class)))->keyBy('id');

    expect([$items[$site->postFr]['language'], $items[$site->postFr]['translation_group']])->toBe(['fr', $site->postGroup])
        ->and([$items[$site->post]['language'], $items[$site->post]['translation_group']])->toBe([null, $site->postGroup])
        ->and([$items[$site->alone]['language'], $items[$site->alone]['translation_group']])->toBe([null, null])
        ->and([$terms[$site->newsFr]['language'], $terms[$site->newsFr]['translation_group']])->toBe(['fr', $site->newsGroup])
        ->and(app(ContentsKind::class)->version())->toBe(2)
        ->and(app(TermsKind::class)->version())->toBe(2)
        ->and(app(ContentsKind::class)->fields())->toHaveKeys(['language', 'translation_group'])
        ->and(app(TermsKind::class)->fields()['language'])->toBe('The language it is written in, as a BCP 47 tag; null for the site\'s default language.');
});
