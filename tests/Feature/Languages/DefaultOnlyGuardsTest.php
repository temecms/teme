<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Languages\Operations\ChangeTranslatedTypes;
use App\Domain\Languages\Operations\Input\ChangeTranslatedTypesInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Regions\Regions;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Site\ListingViews;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Taxonomy\Models\Term;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->site = LanguageSite::build();
});

/**
 * Runs $check with the page cache off, or on and asked twice, so a stored
 * answer is checked as well as a drawn one.
 */
function dogCached(bool $cached, Closure $check): void
{
    $folder = $cached ? cacheOn() : null;

    try {
        $check();

        if ($cached) {
            $check();
        }
    } finally {
        if ($folder !== null) {
            cacheOff($folder);
        }
    }
}

dataset('page cache', ['off' => [false], 'on' => [true]]);

it('lists every shown language\'s items but no hidden one\'s, and only the default language\'s terms, in the sitemaps', function (bool $cached): void {
    // The French item the newest of all, so the index's date would be its.
    test()->travel(1)->minutes();
    DB::table('contents')->where('id', $this->site->postFr)->update(['published_at' => now()]);

    dogCached($cached, function (): void {
        $items = (string) addrGet('/sitemap-items-post.xml')->assertOk()->getContent();
        $terms = (string) addrGet('/sitemap-terms-category.xml')->assertOk()->getContent();

        // Every shown language's items since 6.1c's H3 (I-3360); never a hidden one's.
        expect($items)->toContain('/hello/')->toContain('/fr/bonjour/')->not->toContain('/es/hola/')
            ->and($terms)->toContain('/category/news/')->not->toContain('actualites');
    });

    // The index's date is the default language's newest, not the French one's, a minute later.
    expect(app(ListedItems::class)->newest('post')?->lessThan(now()))->toBeTrue();
})->with('page cache');

it('lists only the default language\'s items in llms.txt', function (bool $cached): void {
    Switches::llmsOn();

    dogCached($cached, function (): void {
        expect((string) addrGet('/llms.txt')->assertOk()->getContent())->toContain('Hello')->not->toContain('Bonjour')->not->toContain('Hola');
    });
})->with('page cache');

it('serves a .md page for a shown language\'s item and never a hidden one\'s', function (bool $cached): void {
    Switches::llmsOn();

    dogCached($cached, function (): void {
        expect((string) addrGet('/fr/bonjour.md')->assertOk()->getContent())->toStartWith('# Bonjour');
        addrGet('/es/hola.md')->assertNotFound();
    });
})->with('page cache');

it('lists only the default language\'s items in every feed', function (bool $cached): void {
    app(PathProjection::class)->syncUser($this->site->editor);
    $author = addrCurrent('user', (string) $this->site->editor->id);
    // The French post filed under the default category too, as a shared taxonomy's term would be.
    DB::table('content_terms')->insert(['content_id' => $this->site->postFr, 'term_id' => $this->site->news, 'scope' => 'published', 'position' => 1]);

    dogCached($cached, function () use ($author): void {
        foreach (['/feed/', '/category/news/feed/', "/{$author}/feed/"] as $feed) {
            expect((string) addrGet($feed)->assertOk()->getContent())->toContain('Hello')->not->toContain('Bonjour')->not->toContain('Hola');
        }
    });
})->with('page cache');

it('lists only the default language\'s items and terms in the read API, and answers a hidden item as one that never existed', function (bool $cached): void {
    Switches::apiOn();
    $esTerm = LanguageSite::term($this->site->editor, 'Noticias', 'es', null);
    $unknown = strtolower((string) Str::ulid());

    dogCached($cached, function () use ($esTerm, $unknown): void {
        $items = array_column($this->getJson('/api/v1/items')->assertOk()->json('data'), 'title');
        $terms = array_column($this->getJson('/api/v1/terms')->assertOk()->json('data'), 'name');

        expect($items)->toContain('Hello')->not->toContain('Bonjour')->not->toContain('Hola')
            ->and($terms)->toContain('News')->not->toContain('Actualités')->not->toContain('Noticias');

        $missing = $this->getJson('/api/v1/items/'.$unknown)->assertNotFound()->json();
        $hidden = $this->getJson('/api/v1/items/'.$this->site->postEs)->assertNotFound()->json();

        expect([$hidden['code'], $hidden['status']])->toBe([$missing['code'], $missing['status']])
            ->and($hidden['detail'])->toBe(str_replace($unknown, $this->site->postEs, $missing['detail']));

        $this->getJson('/api/v1/items/by-path?path='.rawurlencode('/es/hola/'))->assertNotFound()->assertJsonPath('code', $missing['code']);
        $this->getJson('/api/v1/terms/'.$esTerm->id)->assertNotFound()->assertJsonPath('code', $missing['code']);
        $this->getJson('/api/v1/items/'.$this->site->postFr)->assertOk()->assertJsonPath('data.language', 'fr');
    });
})->with('page cache');

it('answers a French parent\'s list as an unknown parent\'s, and names a French page\'s French parent', function (): void {
    Switches::apiOn();
    listsPublish(Content::query()->findOrFail($this->site->pageFr), $this->site->editor);
    $child = LanguageSite::version($this->site->editor, 'Équipe', 'fr', null, [], publish: false, type: 'page');
    DB::table('contents')->where('id', $child)->update(['parent_id' => $this->site->pageFr]);
    listsPublish(Content::query()->findOrFail($child), $this->site->editor);
    $unknown = strtolower((string) Str::ulid());

    $french = $this->getJson('/api/v1/items?type=page&parent='.$this->site->pageFr)->assertOk()->json();
    $none = $this->getJson('/api/v1/items?type=page&parent='.$unknown)->assertOk()->json();
    unset($french['links']['self'], $none['links']['self']);

    $item = $this->getJson('/api/v1/items/'.$child)->assertOk()->json('data');

    expect($french)->toBe($none)
        ->and($item['parent_id'])->toBe($this->site->pageFr)
        ->and(array_column($item['ancestors'], 'id'))->toBe([$this->site->pageFr])
        ->and($item['language'])->toBe('fr');
});

it('draws the default language\'s region on a default-language page while a French version exists', function (): void {
    $region = regionCreate($this->site->editor);
    regionSave($region, $this->site->editor, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Join the newsletter']]]]);
    listsPublish($region->refresh(), $this->site->editor);

    // Its French version, the newer: the same slug, in French.
    $row = (array) DB::table('contents')->where('id', $region->id)->first();
    $revision = (array) DB::table('content_revisions')->where('id', $row['published_revision_id'])->first();
    $frId = strtolower((string) Str::ulid());
    $frRevision = strtolower((string) Str::ulid());
    DB::table('contents')->insert([...$row, 'id' => $frId, 'language' => 'fr', 'working_revision_id' => null, 'published_revision_id' => null, 'created_at' => now()->addMinute()]);
    DB::table('content_revisions')->insert([...$revision, 'id' => $frRevision, 'content_id' => $frId, 'document' => json_encode(docWith(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Rejoignez la lettre']]]))]);
    DB::table('contents')->where('id', $frId)->update(['published_revision_id' => $frRevision, 'working_revision_id' => $frRevision]);

    app()->forgetInstance(Regions::class);

    expect((string) addrGet('/hello/')->assertOk()->getContent())->toContain('Join the newsletter')->not->toContain('Rejoignez la lettre');
});

it('gives a body\'s link to a hidden language\'s item as its text alone in the read API, as the page draws it', function (bool $cached): void {
    Switches::apiOn();
    $link = fn (string $text, string $id): array => ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$id]]]];
    $linker = lifecycleDraft($this->site->editor, 'Linker');
    docSave($linker, $this->site->editor, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        $link('in Spanish', $this->site->postEs),
        ['type' => 'text', 'text' => ' and '],
        $link('in French', $this->site->postFr),
    ]]]]);
    listsPublish($linker, $this->site->editor);
    $address = addrCurrent('content', $linker->id);

    dogCached($cached, function () use ($linker, $address): void {
        foreach (['/api/v1/items/'.$linker->id, '/api/v1/items/by-path?path='.rawurlencode('/'.$address.'/')] as $url) {
            $body = $this->getJson($url)->assertOk()->json('data.body');
            $runs = collect($body['json']['content'][0]['content'])->keyBy('text');

            expect($runs['in Spanish'])->toEqual(['type' => 'text', 'text' => 'in Spanish'])
                ->and($runs['in French']['marks'][0]['attrs']['href'])->toEndWith('/fr/bonjour/')
                ->and((string) json_encode($body, JSON_UNESCAPED_SLASHES))->not->toContain('/es/hola/')->not->toContain($this->site->postEs)
                ->and($body['html'])->toContain('in Spanish')->not->toContain('/es/hola/');
        }

        expect((string) $this->getJson('/api/v1/items')->assertOk()->getContent())->not->toContain('hola');
    });
})->with('page cache');

it('answers a list filtered by another language\'s term, hidden or shown, exactly as one filtered by a made-up term', function (): void {
    Switches::apiOn();
    $noticias = $this->site->newsEs();
    $unknown = strtolower((string) Str::ulid());
    $answer = function (string $term) use ($unknown): array {
        $response = $this->getJson('/api/v1/items?term='.$term);

        return [$response->status(), str_replace($term, $unknown, (string) $response->getContent())];
    };

    $madeUp = $answer($unknown);

    expect($madeUp[0])->toBe(400)
        ->and($answer($this->site->newsFr))->toBe($madeUp)
        ->and($answer($noticias))->toBe($madeUp)
        ->and($this->getJson('/api/v1/items?term='.$this->site->news)->assertOk()->json('data.0.title'))->toBe('Hello');
});

it('counts a shared taxonomy\'s term by the default language\'s items it lists, in its archive, the sitemap and the read API', function (): void {
    Switches::apiOn();
    $version = (int) DB::table('settings')->where('group_key', 'languages')->value('version');
    app(ChangeTranslatedTypes::class)->execute(new ChangeTranslatedTypesInput([], ['tag'], $version, (string) $this->site->admin->id));
    app()->forgetInstance(SiteLanguages::class);
    $local = typesTerm($this->site->editor, 'tag', 'Local');
    $seul = typesTerm($this->site->editor, 'tag', 'Seul');

    foreach ([['Derby', null, [$local->id]], ['Rencontre', 'fr', [$local->id, $seul->id]]] as [$title, $language, $terms]) {
        $item = Content::query()->findOrFail(LanguageSite::version($this->site->editor, $title, $language, null, [], publish: false));
        docSave($item, $this->site->editor, docWith(docText($title)), terms: $terms);
        listsPublish($item, $this->site->editor);
    }

    $reading = (int) DB::table('settings')->where('group_key', 'reading')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('reading', ['posts_per_page' => 1], [], [], $reading, (string) $this->site->admin->id));

    // One page holds one item: the default language's one item makes one page, with no next.
    $archive = app(ListingViews::class)->forTerm(Term::query()->findOrFail($local->id), 1);

    expect(array_column($archive->items, 'title'))->toBe(['Derby'])
        ->and([$archive->pagination->total, $archive->pagination->next])->toBe([1, null]);
    addrGet('/'.addrCurrent('term', $local->id).'/page/2/')->assertNotFound();

    expect((string) addrGet('/sitemap-terms-tag.xml')->getContent())->not->toContain('/seul/');

    $counts = collect($this->getJson('/api/v1/terms?taxonomy=tag')->assertOk()->json('data'))->pluck('count', 'name')->all();

    expect($counts['Local'])->toBe(1)
        ->and($counts['Seul'] ?? 0)->toBe(0);
});

it('links from a shared taxonomy\'s term only the children whose default-language items its archive lists, in one statement', function (): void {
    // Categories stop being translated: the fixture's French one goes, and News leaves its group.
    DB::table('terms')->where('id', $this->site->newsFr)->delete();
    DB::table('terms')->where('id', $this->site->news)->update(['translation_group' => null]);
    $version = (int) DB::table('settings')->where('group_key', 'languages')->value('version');
    app(ChangeTranslatedTypes::class)->execute(new ChangeTranslatedTypesInput([], ['category'], $version, (string) $this->site->admin->id));
    app()->forgetInstance(SiteLanguages::class);
    $sport = typesTerm($this->site->editor, 'category', 'Sport');
    $football = typesTerm($this->site->editor, 'category', 'Football', (string) $sport->id);
    $rugby = typesTerm($this->site->editor, 'category', 'Rugby', (string) $sport->id);

    foreach ([['Derby', null, [$football->id]], ['Rencontre', 'fr', [$rugby->id]]] as [$title, $language, $terms]) {
        $item = Content::query()->findOrFail(LanguageSite::version($this->site->editor, $title, $language, null, [], publish: false));
        docSave($item, $this->site->editor, docWith(docText($title)), terms: $terms);
        listsPublish($item, $this->site->editor);
    }

    $term = Term::query()->findOrFail($sport->id);
    app(ListingViews::class)->forTerm($term, 1);
    $statements = count(dashQueries(fn () => app(ListingViews::class)->forTerm($term, 1)));
    $children = app(ListingViews::class)->forTerm($term, 1)->subject->children;

    expect(array_map(fn (object $child): string => $child->name, $children))->toBe(['Football']);

    // A third child with a default-language item costs no statement more.
    $cricket = typesTerm($this->site->editor, 'category', 'Cricket', (string) $sport->id);
    $item = Content::query()->findOrFail(LanguageSite::version($this->site->editor, 'Test match', null, null, [], publish: false));
    docSave($item, $this->site->editor, docWith(docText('Test match')), terms: [$cricket->id]);
    listsPublish($item, $this->site->editor);
    app(ListingViews::class)->forTerm($term, 1);

    expect(count(dashQueries(fn () => app(ListingViews::class)->forTerm($term, 1))))->toBe($statements)
        ->and(array_map(fn (object $child): string => $child->name, app(ListingViews::class)->forTerm($term, 1)->subject->children))->toBe(['Cricket', 'Football']);
});
