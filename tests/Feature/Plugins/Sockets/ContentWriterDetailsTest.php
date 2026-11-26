<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Hooks\Payloads;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\WriteRefused;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('librarian');
    $this->site = LanguageSite::build();

    $this->writer = fn (): ContentWriter => app(PluginDoors::class)
        ->for('tests/librarian', 'librarian', ['content.read', 'content.write'], [])[ContentWriter::class];

    $this->as = function (User $person): string {
        app(CurrentJobActor::class)->set(JobActor::person($person->id));

        return (string) $person->id;
    };

    $this->written = fn (): array => [
        'contents' => Content::withTrashed()->count(),
        'revisions' => ContentRevision::query()->count(),
        'bylines' => DB::table('content_bylines')->count(),
        'groups' => DB::table('contents')->whereNotNull('translation_group')->orderBy('id')->pluck('translation_group', 'id')->all(),
        'slugs' => DB::table('contents')->orderBy('id')->pluck('slug', 'id')->all(),
    ];
});

afterEach(function (): void {
    PluginFixtures::forget();
    app(CurrentJobActor::class)->clear();
});

/**
 * The operator's actor, made as the shell would make it, with no elevation left behind.
 */
function cwOperator(): JobActor
{
    app()->instance(Elevation::class, new OperatorElevation);
    $actor = JobActor::operator();
    app()->forgetInstance(Elevation::class);

    return $actor;
}

/**
 * The account IDs an item credits, in order.
 *
 * @return list<string>
 */
function cwCredits(string $contentId): array
{
    return DB::table('content_bylines as b')->join('people as p', 'p.id', '=', 'b.person_id')
        ->where('b.content_id', $contentId)->orderBy('b.position')->pluck('p.user_id')->map(fn (mixed $id): string => (string) $id)->all();
}

function cwBody(string $text = 'Words'): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

it('creates an item with its slug, the people it credits, and as a translation of another', function (): void {
    $editor = $this->site->editor;
    $person = ($this->as)($editor);

    $item = ($this->writer)()->create(new NewItem(
        type: 'post',
        title: 'Seul au monde',
        body: cwBody(),
        language: 'fr',
        slug: 'seul-au-monde',
        translationOf: $this->site->alone,
        credits: [(string) $this->site->admin->id, $person],
    ), $person);

    $content = Content::query()->findOrFail($item->id);

    expect($content->slug)->toBe('seul-au-monde')
        ->and($content->language)->toBe('fr')
        ->and(cwCredits($item->id))->toBe([(string) $this->site->admin->id, $person])
        ->and($content->translation_group)->not->toBeNull()
        ->and(Content::query()->findOrFail($this->site->alone)->translation_group)->toBe($content->translation_group)
        ->and($item->url)->toBeNull();
});

it('credits the acting person when it names nobody', function (): void {
    $person = ($this->as)($this->site->editor);

    $item = ($this->writer)()->create(new NewItem('post', 'Plain', cwBody()), $person);

    expect(cwCredits($item->id))->toBe([])
        ->and(Content::query()->findOrFail($item->id)->author_id)->toBe($this->site->editor->id);
});

it('changes an item’s slug and credits', function (): void {
    $person = ($this->as)($this->site->editor);
    $writer = ($this->writer)();
    $item = $writer->create(new NewItem('post', 'Harbour', cwBody(), credits: [(string) $this->site->admin->id]), $person);

    $changed = $writer->change($item, new ItemChanges(title: 'Harbour again', slug: 'the-harbour', credits: [$person]), $person);

    expect(Content::query()->findOrFail($item->id)->slug)->toBe('the-harbour')
        ->and($changed->title)->toBe('Harbour again')
        ->and(cwCredits($item->id))->toBe([$person]);

    $writer->change($changed, new ItemChanges(credits: []), $person);

    expect(cwCredits($item->id))->toBe([])
        ->and(Content::query()->findOrFail($item->id)->slug)->toBe('the-harbour');
});

it('links existing items as language versions', function (): void {
    $person = ($this->as)($this->site->editor);
    $seul = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);

    ($this->writer)()->linkTranslations([$this->site->alone, $seul], $person);

    $groups = DB::table('contents')->whereIn('id', [$this->site->alone, $seul])->pluck('translation_group')->unique()->all();

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->not->toBeNull();
});

it('refuses a create, writing nothing, when a detail is refused', function (Closure $item, string $words, string $who = 'editor'): void {
    $actor = $who === 'editor' ? $this->site->editor : User::factory()->create()->assignRole('author');
    $person = ($this->as)($actor);
    $before = ($this->written)();

    expect(fn () => ($this->writer)()->create($item($this->site), $person))->toThrow(WriteRefused::class, $words);
    expect(($this->written)())->toBe($before);
})->with([
    'a taken slug' => [fn (LanguageSite $site): NewItem => new NewItem('post', 'Another hello', cwBody(), slug: 'hello'), 'Another post already uses this address.'],
    'a slug that isn’t one' => [fn (LanguageSite $site): NewItem => new NewItem('post', 'Bad', cwBody(), slug: 'Not A Slug'), 'An address is lower-case letters, numbers and hyphens'],
    'crediting another account without the right' => [fn (LanguageSite $site): NewItem => new NewItem('post', 'Mine', cwBody(), credits: [(string) $site->admin->id]), 'Only someone who may edit anyone', 'author'],
    'a person who isn’t an account' => [fn (LanguageSite $site): NewItem => new NewItem('post', 'Nobody', cwBody(), credits: ['person:abc']), 'A credit names an account by its ID.'],
    'a translation in the same language' => [fn (LanguageSite $site): NewItem => new NewItem('post', 'Hello twice', cwBody(), translationOf: $site->post), 'Each language can have one version.'],
    'a translation of another type' => [fn (LanguageSite $site): NewItem => new NewItem('page', 'Une page', cwBody(), language: 'fr', translationOf: $site->alone), 'a translation is of the same type.'],
    'a translation of a trashed item' => [function (LanguageSite $site): NewItem {
        pageTrash(Content::query()->findOrFail($site->alone), $site->editor);

        return new NewItem('post', 'Seul', cwBody(), language: 'fr', translationOf: $site->alone);
    }, 'Restore it first.'],
]);

it('refuses a change, writing nothing, when its slug or credits are refused', function (Closure $changes, string $words, string $who = 'editor'): void {
    $actor = $who === 'editor' ? $this->site->editor : User::factory()->create()->assignRole('author');
    $person = ($this->as)($actor);
    $item = ($this->writer)()->create(new NewItem('post', 'Harbour', cwBody()), $person);
    $before = ($this->written)();

    expect(fn () => ($this->writer)()->change($item, $changes($this->site), $person))->toThrow(WriteRefused::class, $words);
    expect(($this->written)())->toBe($before);
})->with([
    'a taken slug' => [fn (LanguageSite $site): ItemChanges => new ItemChanges(title: 'Changed', slug: 'hello'), 'Another post already uses this address.'],
    'crediting another account without the right' => [fn (LanguageSite $site): ItemChanges => new ItemChanges(title: 'Changed', credits: [(string) $site->admin->id]), 'Only someone who may edit anyone', 'author'],
]);

it('refuses to link versions, writing nothing, when they can’t be linked', function (): void {
    $person = ($this->as)($this->site->editor);
    $before = ($this->written)();

    expect(fn () => ($this->writer)()->linkTranslations([$this->site->alone, $this->site->post], $person))->toThrow(WriteRefused::class, 'Each language can have one version.')
        ->and(fn () => ($this->writer)()->linkTranslations([$this->site->alone], $person))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->writer)()->linkTranslations([$this->site->alone, $this->site->pageFr], $person))->toThrow(WriteRefused::class, 'a translation is of the same type.');
    expect(($this->written)())->toBe($before);
});

it('refuses every detail for anyone but the person the work acts for, and for the operator', function (): void {
    app(CurrentJobActor::class)->set(cwOperator());
    $writer = ($this->writer)();

    expect(fn () => $writer->create(new NewItem('post', 'Op', cwBody(), slug: 'op'), (string) $this->site->editor->id))->toThrow(WriteRefused::class)
        ->and(fn () => $writer->linkTranslations([$this->site->alone, LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: false)], (string) $this->site->editor->id))->toThrow(WriteRefused::class);

    app(CurrentJobActor::class)->set(JobActor::person($this->site->editor->id));

    expect(fn () => $writer->linkTranslations([$this->site->alone, $this->site->pageFr], (string) $this->site->admin->id))->toThrow(WriteRefused::class, 'A plugin writes only for the person who started this work.');
    expect(Payloads::item(Content::query()->findOrFail($this->site->alone))->title)->toBe('Alone');
});
