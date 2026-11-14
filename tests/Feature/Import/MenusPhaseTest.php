<?php

declare(strict_types=1);

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Menus\MenusPhase;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\Operations\DeleteNavigation;
use App\Domain\Menus\Operations\Input\DeleteNavigationInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\MenuImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->companion = importFake();
    $this->source = importSource();
    $this->about = addrPublished($this->admin, 'About', 'page');
    MenuImports::post($this->source, 20, $this->about, ['menu_order' => 2, 'parent' => 0]);
});

/**
 * The companion's whole answer: one classic menu, 3, holding these items.
 *
 * @param  list<array<string, mixed>>  $items
 *
 * @return array<string, mixed>
 */
function mnMain(array $items, string $name = 'Main'): array
{
    return MenuImports::answer([MenuImports::menu(3, $items, $name)]);
}

it('registers the menus phase at 800, in every kind of run', function (): void {
    $phases = app(ImportPhases::class);

    expect($phases->find('menus'))->toBeInstanceOf(MenusPhase::class)
        ->and($phases->find('menus')?->order())->toBe(800)
        ->and($phases->find('menus')?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});

it('brings the companion’s classic menus across, each item leading by ID to what the import brought', function (): void {
    $team = addrPublished($this->admin, 'Team', 'page');
    $contact = addrPublished($this->admin, 'Contact', 'page');
    $privacy = addrPublished($this->admin, 'Privacy Policy', 'page');
    $news = taxonomyTerm('category', 'News');
    $ferries = taxonomyTerm('tag', 'Ferries');
    MenuImports::post($this->source, 21, $team);
    MenuImports::post($this->source, 22, $contact);
    MenuImports::post($this->source, 23, $privacy);
    MenuImports::term($this->source, 70, $news, 7, 'category');
    MenuImports::term($this->source, 80, $ferries, 8, 'post_tag');

    $run = MenuImports::run($this->companion, $this->source, null, actor: $this->admin);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(MenuImports::entry($this->source, '3')->only(['teme_type', 'outcome']))->toBe(['teme_type' => 'navigation', 'outcome' => ImportOutcome::Created])
        ->and(MenuImports::navigationOf($this->source, '3')->name)->toBe('Main')
        ->and(MenuImports::stored(MenuImports::navigationOf($this->source, '3')))->toBe([
            ['kind' => 'home', 'target_id' => null, 'url' => null, 'label' => 'Home', 'opens_new_tab' => false, 'children' => []],
            ['kind' => 'content', 'target_id' => $this->about->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => [
                ['kind' => 'content', 'target_id' => $team->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => []],
            ]],
            ['kind' => 'term', 'target_id' => $news->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => [
                ['kind' => 'term', 'target_id' => $ferries->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => []],
            ]],
            ['kind' => 'url', 'target_id' => null, 'url' => 'https://harbour-trust.example/', 'label' => 'Harbour Trust', 'opens_new_tab' => true, 'children' => []],
        ])
        ->and(MenuImports::stored(MenuImports::navigationOf($this->source, '4')))->toBe([
            ['kind' => 'content', 'target_id' => $contact->id, 'url' => null, 'label' => 'Contact us', 'opens_new_tab' => false, 'children' => []],
            ['kind' => 'content', 'target_id' => $privacy->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => []],
            ['kind' => 'url', 'target_id' => null, 'url' => 'mailto:hello@old.example', 'label' => 'Email us', 'opens_new_tab' => false, 'children' => []],
            ['kind' => 'url', 'target_id' => null, 'url' => '/feed/', 'label' => 'RSS', 'opens_new_tab' => false, 'children' => []],
        ])
        ->and(MenuImports::codes($this->source, '3'))->toBe([])
        ->and(MenuImports::codes($this->source, '4'))->toBe([]);
});

it('brings a published navigation post across as a menu of its own, and leaves a draft one', function (): void {
    MenuImports::run($this->companion, $this->source, MenuImports::answer([], [
        MenuImports::navigation(90, [
            MenuImports::block('core/navigation-link', ['label' => 'About us', 'kind' => 'post-type', 'type' => 'page', 'id' => 20, 'url' => 'https://old.example/about/']),
            MenuImports::block('core/home-link'),
        ], 'Header &amp; more'),
        MenuImports::navigation(91, [MenuImports::block('core/home-link')], 'Old header', 'draft'),
    ]));
    $menu = MenuImports::navigationOf($this->source, 'navigation:90');

    expect($menu->name)->toBe('Header & more')
        ->and(MenuImports::stored($menu))->toBe([
            ['kind' => 'content', 'target_id' => $this->about->id, 'url' => null, 'label' => 'About us', 'opens_new_tab' => false, 'children' => []],
            ['kind' => 'home', 'target_id' => null, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => []],
        ])
        ->and(MenuImports::messages($this->source, 'navigation:90'))->toBe([
            ['menu.not-placed', 'A block theme showed it from its templates, which Teme doesn’t read, so it isn’t shown anywhere yet: choose its place under Content › Navigation.'],
        ])
        ->and(MenuImports::entry($this->source, 'navigation:91')->outcome)->toBe(ImportOutcome::Skipped)
        ->and(MenuImports::messages($this->source, 'navigation:91'))->toBe([
            ['menu.draft', 'It’s a draft on WordPress, which no page there shows, so it wasn’t imported.'],
        ])
        ->and(ImportMapEntry::query()->where('source_id', $this->source->id)->where('object_type', 'post')->pluck('object_id')->all())->toBe(['20']);
});

it('leads an item to its old address here when the import didn’t bring what it led to', function (Closure $arrange, array $fields, string $path): void {
    $arrange($this);
    MenuImports::run($this->companion, $this->source, mnMain([MenuImports::item(101, ['label' => 'Gone', 'label_is_custom' => false, ...$fields])]));

    expect(MenuImports::stored(MenuImports::navigationOf($this->source, '3')))->toBe([
        ['kind' => 'url', 'target_id' => null, 'url' => $path, 'label' => 'Gone', 'opens_new_tab' => false, 'children' => []],
    ])
        ->and(MenuImports::messages($this->source, '3'))->toBe([
            ['menu.not-imported', "“Gone” led to something this import doesn’t bring, so it leads to its old address, {$path}."],
        ])
        ->and(NavigationItem::query()->where('url', 'like', '%old.example%')->exists())->toBeFalse();
})->with([
    'a custom type’s item' => [fn ($test) => null, ['type' => 'post_type', 'object' => 'movie', 'object_id' => 40, 'url' => 'https://old.example/movies/alien/'], '/movies/alien/'],
    'an attachment’s page' => [fn ($test) => null, ['type' => 'post_type', 'object' => 'attachment', 'object_id' => 41, 'url' => 'https://old.example/harbour/photo/'], '/harbour/photo/'],
    'a page deleted here since' => [
        fn ($test) => ImportRecords::entry($test->source->id, ImportRecords::run($test->source->id), 'post', '42', 'created'),
        ['type' => 'post_type', 'object' => 'page', 'object_id' => 42, 'url' => 'https://old.example/gone/'],
        '/gone/',
    ],
    'a term of a taxonomy Teme doesn’t have' => [fn ($test) => null, ['type' => 'taxonomy', 'object' => 'genre', 'object_id' => 9, 'url' => 'https://old.example/genre/horror/'], '/genre/horror/'],
]);

it('waits for a page that failed, and brings the whole menu once the page comes', function (): void {
    $post = MenuImports::post($this->source, 43, null, outcome: 'failed');
    $answer = mnMain([
        MenuImports::item(101, ['type' => 'post_type', 'object' => 'page', 'object_id' => 43, 'url' => 'https://old.example/later/', 'label' => 'Later', 'label_is_custom' => false]),
        MenuImports::item(102),
    ]);

    MenuImports::run($this->companion, $this->source, $answer);

    expect(MenuImports::entry($this->source, '3')->outcome)->toBe(ImportOutcome::Failed)
        ->and(MenuImports::messages($this->source, '3'))->toBe([
            ['menu.waiting', 'It leads to a post or page that couldn’t be imported (WordPress ID 43), so it waits for it: a full import tries both again.'],
        ])
        ->and(Navigation::query()->count())->toBe(0);

    $later = addrPublished($this->admin, 'Later', 'page');
    DB::table('import_map')->where('id', $post)->update(['teme_type' => 'content', 'teme_id' => $later->id, 'status' => 'imported', 'outcome' => 'created']);
    MenuImports::run($this->companion, $this->source, $answer);

    expect(MenuImports::entry($this->source, '3')->outcome)->toBe(ImportOutcome::Created)
        ->and(array_column(MenuImports::stored(MenuImports::navigationOf($this->source, '3')), 'target_id'))->toBe([$later->id, null]);
});

it('leads to the old address once its own retry finds the page still failing, so no menu waits for ever', function (): void {
    MenuImports::post($this->source, 43, null, outcome: 'failed');
    $answer = mnMain([
        MenuImports::item(101, ['type' => 'post_type', 'object' => 'page', 'object_id' => 43, 'url' => 'https://old.example/later/', 'label' => 'Later', 'label_is_custom' => false]),
        MenuImports::item(102),
    ]);

    MenuImports::run($this->companion, $this->source, $answer);
    MenuImports::run($this->companion, $this->source, $answer);

    expect(MenuImports::entry($this->source, '3')->outcome)->toBe(ImportOutcome::Created)
        ->and(array_column(MenuImports::stored(MenuImports::navigationOf($this->source, '3')), 'url'))->toBe(['/later/', null])
        ->and(MenuImports::codes($this->source, '3'))->toBe(['menu.not-imported']);
});

it('never keeps the old site’s address: its links become paths here, its home the home page', function (): void {
    MenuImports::run($this->companion, $this->source, mnMain([
        MenuImports::item(101, ['url' => 'https://www.old.example/about/', 'label' => 'About us']),
        MenuImports::item(102, ['url' => 'http://old.example/nowhere/?a=1#top', 'label' => 'Nowhere']),
        MenuImports::item(103, ['url' => '//old.example', 'label' => 'Start']),
        MenuImports::item(104, ['url' => 'https://u@old.example/private/', 'label' => 'Sign in']),
    ]));

    expect(MenuImports::stored(MenuImports::navigationOf($this->source, '3')))->toBe([
        ['kind' => 'content', 'target_id' => $this->about->id, 'url' => null, 'label' => 'About us', 'opens_new_tab' => false, 'children' => []],
        ['kind' => 'url', 'target_id' => null, 'url' => '/nowhere/?a=1#top', 'label' => 'Nowhere', 'opens_new_tab' => false, 'children' => []],
        ['kind' => 'home', 'target_id' => null, 'url' => null, 'label' => 'Start', 'opens_new_tab' => false, 'children' => []],
    ])
        ->and(NavigationItem::query()->where('url', 'like', '%old.example%')->exists())->toBeFalse()
        ->and(MenuImports::codes($this->source, '3'))->toBe(['menu.address-refused']);
});

it('makes a # item over sub-items a heading, and leaves out what a menu can’t hold, saying so', function (): void {
    MenuImports::run($this->companion, $this->source, mnMain([
        MenuImports::item(101, ['url' => 'javascript:openChat()', 'label' => 'Chat']),
        MenuImports::item(102, ['parent' => 101, 'url' => 'tel:+441234567890', 'label' => 'Call us']),
        MenuImports::item(103, ['url' => '#', 'label' => 'Spacer']),
        MenuImports::item(104, ['url' => '#visit', 'label' => 'Visit']),
        MenuImports::item(105, ['parent' => 104, 'url' => 'https://partner.example/', 'label' => 'Partner']),
        MenuImports::item(106, ['url' => '#top', 'label' => 'Top']),
    ]));

    expect(MenuImports::stored(MenuImports::navigationOf($this->source, '3')))->toBe([
        ['kind' => 'url', 'target_id' => null, 'url' => 'tel:+441234567890', 'label' => 'Call us', 'opens_new_tab' => false, 'children' => []],
        ['kind' => 'heading', 'target_id' => null, 'url' => null, 'label' => 'Visit', 'opens_new_tab' => false, 'children' => [
            ['kind' => 'url', 'target_id' => null, 'url' => 'https://partner.example/', 'label' => 'Partner', 'opens_new_tab' => false, 'children' => []],
        ]],
    ])
        ->and(MenuImports::messages($this->source, '3'))->toBe([
            ['menu.address-refused', '“Chat” led to javascript:openChat(), which a Teme menu can’t hold, so it was left out.'],
            ['menu.no-address', '“Spacer” had no address and nothing under it to lead to, so it was left out.'],
            ['menu.no-address', '“Top” had no address and nothing under it to lead to, so it was left out.'],
        ]);
});

it('lists the pages a page list showed: this source’s published pages, in WordPress’s order, each under its parent', function (): void {
    $visit = addrPublished($this->admin, 'Visit', 'page');
    $team = addrPublished($this->admin, 'Team', 'page');
    MenuImports::post($this->source, 21, $visit, ['menu_order' => 1, 'parent' => 0]);
    MenuImports::post($this->source, 22, $team, ['menu_order' => 0, 'parent' => 20]);
    MenuImports::post($this->source, 23, lifecycleDraft($this->admin, 'Draft', 'page'), ['menu_order' => 0, 'parent' => 0]);
    MenuImports::post($this->source, 24, addrPublished($this->admin, 'Harbour news'), ['menu_order' => 0, 'parent' => 0]);
    addrPublished($this->admin, 'Made here', 'page');

    MenuImports::run($this->companion, $this->source, MenuImports::answer([], [
        MenuImports::navigation(90, [MenuImports::block('core/page-list')]),
        MenuImports::navigation(91, [MenuImports::block('core/page-list', ['parentPageID' => 20])], 'About pages'),
    ]));

    expect(MenuImports::stored(MenuImports::navigationOf($this->source, 'navigation:90')))->toBe([
        ['kind' => 'content', 'target_id' => $visit->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => []],
        ['kind' => 'content', 'target_id' => $this->about->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => [
            ['kind' => 'content', 'target_id' => $team->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => []],
        ]],
    ])
        ->and(array_column(MenuImports::stored(MenuImports::navigationOf($this->source, 'navigation:91')), 'target_id'))->toBe([$team->id])
        ->and(MenuImports::messages($this->source, 'navigation:90'))->toContain(
            ['menu.page-list', 'WordPress’s page list showed every published page; here it’s a fixed list of the 3 pages it showed, and pages added later aren’t added to it.'],
        );
});

it('leaves out a page list with no page to show, saying so', function (): void {
    MenuImports::run($this->companion, $this->source, MenuImports::answer([], [
        MenuImports::navigation(90, [MenuImports::block('core/page-list', ['parentPageID' => 99]), MenuImports::block('core/home-link')]),
    ]));

    expect(array_column(MenuImports::stored(MenuImports::navigationOf($this->source, 'navigation:90')), 'kind'))->toBe(['home'])
        ->and(MenuImports::messages($this->source, 'navigation:90'))->toContain(
            ['menu.page-list-empty', 'WordPress’s page list had no page to show, so it was left out.'],
        );
});

it('writes nothing on a later run when WordPress’s menu hasn’t changed', function (): void {
    $answer = mnMain([MenuImports::item(101)]);
    MenuImports::run($this->companion, $this->source, $answer);
    $items = NavigationItem::query()->pluck('id')->all();
    $first = MenuImports::entry($this->source, '3');

    $again = MenuImports::run($this->companion, $this->source, $answer);

    expect(NavigationItem::query()->pluck('id')->all())->toBe($items)
        ->and(MenuImports::entry($this->source, '3')->run_id)->toBe($first->run_id)
        ->and($again->created_count + $again->updated_count + $again->skipped_count)->toBe(0);
});

it('brings WordPress’s changes to a menu on a later run while it is as the import left it', function (): void {
    MenuImports::run($this->companion, $this->source, mnMain([MenuImports::item(101)]));
    MenuImports::run($this->companion, $this->source, mnMain([
        MenuImports::item(101, ['label' => 'Start']),
        MenuImports::item(102, ['url' => 'https://partner.example/', 'label' => 'Partner']),
    ], 'Main menu'));
    $menu = MenuImports::navigationOf($this->source, '3');

    expect(MenuImports::entry($this->source, '3')->outcome)->toBe(ImportOutcome::Updated)
        ->and($menu->name)->toBe('Main menu')
        ->and(array_column(MenuImports::stored($menu), 'label'))->toBe(['Start', 'Partner']);
});

it('leaves a menu changed on Teme since as it is, saying so', function (): void {
    MenuImports::run($this->companion, $this->source, mnMain([MenuImports::item(101)]));
    $menu = MenuImports::navigationOf($this->source, '3');
    navSave($this->admin, $menu, [['kind' => 'home', 'label' => 'Ours']]);

    MenuImports::run($this->companion, $this->source, mnMain([MenuImports::item(101, ['label' => 'Start'])]));

    expect(MenuImports::entry($this->source, '3')->outcome)->toBe(ImportOutcome::Skipped)
        ->and(MenuImports::messages($this->source, '3'))->toBe([
            ['menu.changed-here', 'It was changed on Teme after an earlier import, so WordPress’s later changes to it weren’t brought across.'],
        ])
        ->and(array_column(MenuImports::stored($menu), 'label'))->toBe(['Ours']);
});

it('leaves a menu deleted on Teme since deleted, saying so', function (): void {
    MenuImports::run($this->companion, $this->source, mnMain([MenuImports::item(101)]));
    app(DeleteNavigation::class)->execute(new DeleteNavigationInput(MenuImports::navigationOf($this->source, '3')->id, (string) $this->admin->id));

    MenuImports::run($this->companion, $this->source, mnMain([MenuImports::item(101, ['label' => 'Start'])]));

    expect(Navigation::query()->count())->toBe(0)
        ->and(MenuImports::messages($this->source, '3'))->toBe([
            ['menu.deleted-here', 'It was deleted on Teme after an earlier import, so it was left deleted.'],
        ]);
});

it('makes a menu Teme takes from however WordPress’s menu is shaped', function (): void {
    $items = [];

    foreach (range(1, 6) as $level) {
        $items[] = MenuImports::item(100 + $level, ['parent' => $level === 1 ? 0 : 99 + $level, 'order' => $level, 'url' => "/level-{$level}/", 'label' => "Level {$level}"]);
    }

    foreach (range(1, 240) as $n) {
        $items[] = MenuImports::item(1000 + $n, ['order' => 10 + $n, 'url' => "https://old.example/item-{$n}/", 'label' => str_repeat('<b>Long</b> ', 50).$n]);
    }

    $items[] = MenuImports::item(301, ['parent' => 302, 'order' => 300, 'label' => 'Ping']);
    $items[] = MenuImports::item(302, ['parent' => 301, 'order' => 301, 'label' => 'Pong']);
    $items[] = MenuImports::item(303, ['parent' => 999, 'order' => 302, 'url' => 'javascript:void(0)', 'label' => 'Orphan script']);

    MenuImports::run($this->companion, $this->source, mnMain($items));
    $menu = MenuImports::navigationOf($this->source, '3');
    $rows = NavigationItem::query()->where('navigation_id', $menu->id)->get();
    $deepest = function (array $tree) use (&$deepest): int {
        $depth = 0;

        foreach ($tree as $row) {
            $depth = max($depth, 1 + $deepest($row['children']));
        }

        return $depth;
    };

    expect(MenuImports::entry($this->source, '3')->outcome)->toBe(ImportOutcome::Created)
        ->and($rows)->toHaveCount(200)
        ->and($deepest(MenuImports::stored($menu)))->toBe(3)
        ->and($rows->every(fn (NavigationItem $item): bool => $item->label === null || mb_strlen($item->label) <= 200))->toBeTrue()
        ->and(MenuImports::codes($this->source, '3'))->toBe(['menu.address-refused', 'menu.extras', 'menu.orphan', 'menu.orphan', 'menu.too-deep', 'menu.too-long']);
});

it('names a menu as WordPress did, within what a Teme menu’s name holds', function (string $name, string $kept): void {
    MenuImports::run($this->companion, $this->source, mnMain([MenuImports::item(101)], $name));

    expect(MenuImports::navigationOf($this->source, '3')->name)->toBe($kept);
})->with([
    'escaped once' => ['Boats &amp; Ferries', 'Boats & Ferries'],
    'none' => ['', 'Menu'],
    'too long' => [str_repeat('Harbour ', 20), rtrim(mb_substr(trim(str_repeat('Harbour ', 20)), 0, 120))],
]);
