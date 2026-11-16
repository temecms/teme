<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\LinkTranslationsInput;
use App\Domain\Contents\Operations\LinkTranslations;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Languages\Operations\ChangeSiteLanguage;
use App\Domain\Languages\Operations\Input\ChangeSiteLanguageInput;
use App\Domain\Languages\PrefixConflicts;
use App\Domain\Menus\NavigationTree;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\PageFamily;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\Contents\Visibilities;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    $this->hola = Content::query()->findOrFail($this->site->postEs);
});

it('answers 404 for every address under a hidden language\'s prefix', function (): void {
    app(PathProjection::class)->syncUser($this->site->editor);
    $author = addrCurrent('user', (string) $this->site->editor->id);

    foreach (['/es/', '/es/hola/', "/es/{$author}/", '/es/feed/'] as $address) {
        addrGet($address)->assertNotFound()->assertHeader('Content-Language', 'en-GB');
    }
});

it('draws a link to an item or term in a hidden language as its text alone', function (): void {
    $noticias = LanguageSite::term($this->site->editor, 'Noticias', 'es', null);
    $english = Content::query()->findOrFail($this->site->alone);
    docSave($english, $this->site->editor, docWith(schedLinkTo($this->hola->id, 'the Spanish one'), schedLinkTo($this->site->postFr, 'the French one'), lkLink("teme:term/{$noticias->id}", 'the Spanish news')));
    listsPublish($english->refresh(), $this->site->editor);

    $html = (string) addrGet('/alone/')->assertOk()->getContent();

    expect($html)->toContain('the Spanish one')
        ->not->toContain('>the Spanish one</a>')
        ->not->toContain('/es/hola/')
        ->toContain('the Spanish news')
        ->not->toContain('/es/category/')
        ->toContain('<a href="/fr/bonjour/">the French one</a>');
});

it('answers a hidden item\'s later comment pages, its old addresses and its comment and password routes as missing ones', function (): void {
    cmSettings(['per_page' => 1]);
    $first = cmComment($this->hola);
    cmComment($this->hola);
    DB::table('paths')->insert(['path' => 'hola-viejo', 'target_type' => 'content', 'target_id' => $this->hola->id, 'is_current' => false, 'created_at' => now()]);
    $comments = DB::table('comments')->count();

    addrGet('/es/hola/comments/page/2/')->assertNotFound();
    addrGet('/hola-viejo/')->assertNotFound();
    addrGet("/_teme/comment/{$first->id}")->assertNotFound();
    addrGet("/_teme/comment/{$first->id}/reply")->assertNotFound();
    cmPost($this->hola, ['path' => '/es/hola/'])->assertStatus(422);

    $locked = Visibilities::change($this->hola, 'protected', $this->site->editor, 'Harbour 2024');
    RateLimiter::clear('unlock:127.0.0.1');
    $unlock = $this->post('/_teme/unlock', ['path' => '/es/hola/', 'password' => 'Harbour 2024'], ['Sec-Fetch-Site' => 'same-origin'])->assertStatus(303);

    expect(DB::table('comments')->count())->toBe($comments)
        ->and($unlock->headers->getCookies())->toBe([])
        ->and(RateLimiter::attempts('unlock:127.0.0.1'))->toBe(0)
        ->and($locked->visibility->value)->toBe('protected');
});

it('draws a menu item and an entry field that name a hidden item as a missing item\'s', function (): void {
    $nav = navCreate($this->site->editor);
    navSave($this->site->editor, $nav, [['kind' => 'home'], ['kind' => 'content', 'target_id' => $this->hola->id], ['kind' => 'content', 'target_id' => $this->site->postFr]]);

    $labels = array_column(NavigationTree::for($nav, '/'), 'label');

    app(FieldRegistry::class)->register(new Field('01K6A0P1CE000000000000FC06', 'sequel', 'Sequel', 'Another item.', new EntryField, ['post'], '0.2.0'));
    $post = pagePublish($this->site->editor, 'Part one', [], ['01K6A0P1CE000000000000FC06' => $this->hola->id], [], null);

    expect($labels)->toBe(['Home', 'Bonjour'])
        ->and(app(ArticleViews::class)->published($post->refresh())->field('sequel')->url)->toBeNull();
});

it('refuses a prefix that is all digits, page or comments', function (string $prefix): void {
    expect(PrefixConflicts::for($prefix))->toBe('Use a prefix with a letter in it, not page or comments.');
})->with(['2026', '7', 'page', 'comments']);

it('answers 404 for a hidden language\'s term feed and redirects no old address to one, and serves a shown language\'s (I-3360)', function (bool $cached): void {
    $noticias = $this->site->newsEs();
    $spanish = addrCurrent('term', $noticias);
    $french = addrCurrent('term', $this->site->newsFr);
    DB::table('terms')->where('id', $noticias)->update(['description' => 'Las noticias de la semana.']);
    DB::table('paths')->insert(['path' => 'categoria/viejas', 'target_type' => 'term', 'target_id' => $noticias, 'is_current' => false, 'created_at' => now(), 'replaced_at' => now()]);
    DB::table('paths')->insert(['path' => 'categorie/anciennes', 'target_type' => 'term', 'target_id' => $this->site->newsFr, 'is_current' => false, 'created_at' => now(), 'replaced_at' => now()]);
    $folder = $cached ? cacheOn() : null;

    try {
        foreach (["/{$spanish}/feed/", '/categoria/viejas/feed/'] as $address) {
            for ($twice = 0; $twice < 2; $twice++) {
                addrGet($address)->assertNotFound()->assertDontSee('Noticias')->assertDontSee('Las noticias de la semana.');
            }
        }

        // A shown language's term has its feed, and its old address leads there (6.1c S3).
        for ($twice = 0; $twice < 2; $twice++) {
            addrGet("/{$french}/feed/")->assertOk()->assertSee('Actualités');
            expect(addrLocation(addrGet('/categorie/anciennes/feed/')->assertStatus(301)))->toBe("/{$french}/feed/");
        }

        addrGet('/'.addrCurrent('term', $this->site->news).'/feed/')->assertOk()->assertSee('News');
    } finally {
        if ($folder !== null) {
            cacheOff($folder);
        }
    }
})->with(['page cache off' => false, 'page cache on' => true]);

it('answers a redirect to a hidden language\'s item as one to an item that is gone, and sends readers on once it is shown', function (bool $cached): void {
    rdSaved('/old-hola', item: $this->site->postEs);
    $folder = $cached ? cacheOn() : null;

    try {
        addrGet('/old-hola/')->assertNotFound()->assertDontSee('/es/hola/');
        $version = (int) DB::table('settings')->where('group_key', 'languages')->value('version');
        app(ChangeSiteLanguage::class)->execute(new ChangeSiteLanguageInput('es', 'es', true, $version, (string) $this->site->admin->id));
        expect(addrLocation(addrGet('/old-hola/')->assertStatus(301)))->toBe('/es/hola/');
    } finally {
        if ($folder !== null) {
            cacheOff($folder);
        }
    }
})->with(['page cache off' => false, 'page cache on' => true]);

it('leaves a hidden language\'s page out of a page\'s family, even where an import broke the same-language rule', function (): void {
    $padre = LanguageSite::version($this->site->editor, 'Padre', 'es', null, [], publish: true, type: 'page');
    $hijo = LanguageSite::version($this->site->editor, 'Hijo', 'es', null, [], publish: true, type: 'page');
    $fils = LanguageSite::version($this->site->editor, 'Fils', 'fr', null, [], publish: true, type: 'page');
    // As an import can leave them: a French page under a Spanish one, and a Spanish page under an English one.
    DB::table('contents')->where('id', $fils)->update(['parent_id' => $padre]);
    DB::table('contents')->where('id', $hijo)->update(['parent_id' => $this->site->page]);

    [$ancestors, $parent] = app(PageFamily::class)->lineage($fils);

    expect($ancestors)->toBe([])
        ->and($parent)->toBeNull()
        ->and(array_map(fn ($link): string => $link->title, app(PageFamily::class)->children($this->site->page)))->not->toContain('Hijo');
});

it('answers a feed address under a hidden language\'s prefix exactly as a made-up one there, whatever row it finds', function (bool $cached): void {
    $front = LanguageSite::version($this->site->editor, 'Home', null, null, [], publish: true, type: 'page');
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $front]);
    $inicio = LanguageSite::version($this->site->editor, 'Inicio', 'es', null, [], publish: true, type: 'page');
    app(LinkTranslations::class)->execute(new LinkTranslationsInput([$front, $inicio], (string) $this->site->editor->id));
    expect(addrCurrent('content', $inicio))->toBe('es');
    $folder = $cached ? cacheOn() : null;

    try {
        $answer = function (string $address): array {
            $response = addrGet($address);

            return [$response->getStatusCode(), str_replace($address, '', (string) $response->getContent())];
        };
        $madeUp = $answer('/es/nothing-here/feed/');

        expect($madeUp[0])->toBe(404);

        foreach (['/es/hola/feed/', '/es/feed/', '/es/hola/feed/rss2/'] as $address) {
            for ($twice = 0; $twice < 2; $twice++) {
                expect($answer($address))->toBe($madeUp);
            }
        }
    } finally {
        if ($folder !== null) {
            cacheOff($folder);
        }
    }
})->with(['page cache off' => false, 'page cache on' => true]);
