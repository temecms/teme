<?php

declare(strict_types=1);

use App\Domain\Languages\AdminWords;
use App\Http\Admin\Middleware\UseAdminLanguage;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('puts the admin in the admin\'s language whatever the server\'s locale', function (): void {
    config(['app.locale' => 'fr']);
    app()->setLocale('fr');

    $response = $this->actingAs($this->admin)->get(route('dashboard'));

    $response->assertOk()->assertSee('<html lang="en"', false);
    expect(app()->getLocale())->toBe('en');
});

it('chooses the admin\'s language in the admin\'s group only, before the page\'s props are built', function (): void {
    $groups = app(HttpKernel::class)->getMiddlewareGroups();
    $admin = $groups['admin'];

    expect($admin)->toContain(UseAdminLanguage::class)
        ->and(array_search(UseAdminLanguage::class, $admin, true))->toBe(array_search(HandleInertiaRequests::class, $admin, true) - 1)
        ->and($groups['public'])->not->toContain(UseAdminLanguage::class)
        ->and($groups['reader'])->not->toContain(UseAdminLanguage::class)
        ->and($groups['api'])->not->toContain(UseAdminLanguage::class);
});

it('gives English only the rewordings lang/en.json holds', function (): void {
    $lines = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $catalogue = app(AdminWords::class)->catalogue('en');

    expect($lines)->not->toBe([])
        ->and($catalogue['locale'])->toBe('en')
        ->and($catalogue['messages'])->toBe($lines)
        ->and($catalogue['version'])->toMatch('/^[0-9a-f]{12}$/');
});

it('changes the catalogue\'s version when its lines change', function (): void {
    $before = app(AdminWords::class)->catalogue('en')['version'];
    $folder = sys_get_temp_dir().'/teme-admin-words-'.Str::random(8);
    File::ensureDirectoryExists($folder);
    File::put("{$folder}/en.json", (string) json_encode(['Plugins' => 'Extensions']));

    try {
        app('translator')->addJsonPath($folder);
        $after = app(AdminWords::class)->catalogue('en');
    } finally {
        File::deleteDirectory($folder);
    }

    expect($after['version'])->not->toBe($before)
        ->and($after['messages']['Plugins'])->toBe('Extensions');
});

it('sends the admin\'s words with a visit\'s first page, and not again to a browser that has them', function (): void {
    $catalogue = app(AdminWords::class)->catalogue('en');
    $key = "i18n:en:{$catalogue['version']}";

    $first = $this->actingAs($this->admin)->get(route('dashboard'))->viewData('page');

    expect($first['props']['i18n'])->toBe($catalogue)
        ->and($first['onceProps'])->toHaveKey($key);

    $next = $this->actingAs($this->admin)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $first['version'],
        'X-Inertia-Except-Once-Props' => $key,
    ])->get(route('dashboard'))->assertOk()->json();

    expect($next['props'])->not->toHaveKey('i18n')
        ->and($next['onceProps'])->toHaveKey($key);
});

it('gives the sign-in page the admin\'s words too', function (): void {
    expect($this->get(route('login'))->viewData('page')['props']['i18n']['locale'])->toBe('en');
});

it('translates, fills placeholders and chooses plural forms on the screens as Laravel does', function (): void {
    $messages = ['Hello :name' => 'Hi :name, again', 'Empty line' => ''];
    app('translator')->addLines(['*.Hello :name' => 'Hi :name, again', '*.Empty line' => ''], 'en');

    $words = [
        ['Hello :name', ['name' => 'ada']],
        ['Missing :thing here', ['thing' => 'word']],
        [':name and :NAME and :Name', ['name' => 'ada lovelace']],
        [':count of :countries', ['count' => 3, 'countries' => 'two']],
        ['Costs :price', ['price' => '$1 & :name', 'name' => 'x']],
        ['Empty line', []],
        ['toString', []],
    ];
    $counted = [];

    foreach ([0, 1, 2, 21, -1] as $count) {
        foreach (['One file|:count files', '{0} No files|{1} One file|[2,*] :count files', '[1,19] Some|[20,*] Many'] as $line) {
            $counted[] = [$line, $count, []];
        }
    }

    $counted[] = [':count file|:count files', 1, ['count' => 'one']];

    $php = [
        'words' => array_map(fn (array $case): string => __($case[0], $case[1]), $words),
        'counted' => array_map(fn (array $case): string => trans_choice($case[0], $case[1], $case[2]), $counted),
    ];
    $screens = guardNode('i18n.mjs', [
        'locale' => 'en',
        'messages' => (object) $messages,
        'words' => array_map(fn (array $case): array => [$case[0], (object) $case[1]], $words),
        'counted' => array_map(fn (array $case): array => [$case[0], $case[1], (object) $case[2]], $counted),
    ]);

    // Not two empty answers agreeing: Laravel's own results first.
    expect($php['words'][0])->toBe('Hi ada, again')
        ->and($php['words'][3])->toBe('3 of two')
        ->and($php['words'][4])->toBe('Costs $1 & :name')
        ->and($php['words'][6])->toBe('toString')
        ->and($php['counted'][9])->toBe('21 files')
        ->and($screens)->toBe($php);
});
