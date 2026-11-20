<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Plugins\Operations\SavePluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Guides\GuideScreens;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Support\DemoSite;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/*
 * The pictures of Teme in docs/screenshots, made from the demo site by the
 * Screenshots workflow. Left out of every other run by phpunit.xml; run them
 * with `vendor/bin/pest --group=screenshots`.
 */
beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    // The pictures are served from public/storage, as on a real server, and removed afterwards.
    $this->mediaRoot = storage_path('app/public/demo-media');
    File::deleteDirectory($this->mediaRoot);
    config(['filesystems.disks.media.root' => $this->mediaRoot, 'filesystems.disks.media.url' => '/storage/demo-media']);
    Storage::forgetDisk('media');
    Storage::fake('media-originals');

    if (! file_exists(public_path('storage'))) {
        File::link(storage_path('app/public'), public_path('storage'));
    }

    config(['teme.theme_paths' => []]);
    themeActivate('default');

    $this->site = DemoSite::build();
    $this->out = base_path('docs/screenshots');
    File::ensureDirectoryExists($this->out);

    // Waits for the fonts, every picture on the page, and two frames with nothing moving.
    $this->settle = function (mixed $page): void {
        cmAnswer($page, <<<'JS'
            async () => {
                await document.fonts.ready;
                document.querySelectorAll('img[loading="lazy"]').forEach((img) => { img.loading = 'eager'; });
                await Promise.all([...document.images].map((img) => img.complete ? null : new Promise((done) => { img.addEventListener('load', done, { once: true }); img.addEventListener('error', done, { once: true }); })));
                await Promise.all([...document.images].map((img) => img.decode().catch(() => null)));
                await Promise.all(document.getAnimations().filter((animation) => animation.effect?.getComputedTiming().iterations !== Infinity).map((animation) => animation.finished.catch(() => null)));
                await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
                return true;
            }
            JS, 'settle', once: true);

        cmWaitUntil($page, '[...document.images].every((img) => img.complete && (img.naturalWidth > 0 || img.getBoundingClientRect().width === 0))');
    };

    // Whether two pictures differ by more than the few levels a browser's image scaling varies from run to run.
    $this->differ = function (string $new, string $old): bool {
        $a = is_file($old) ? imagecreatefrompng($old) : false;
        $b = imagecreatefrompng($new);

        if ($a === false || $b === false || imagesx($a) !== imagesx($b) || imagesy($a) !== imagesy($b)) {
            return true;
        }

        for ($y = 0, $height = imagesy($a); $y < $height; $y++) {
            for ($x = 0, $width = imagesx($a); $x < $width; $x++) {
                $p = imagecolorat($a, $x, $y);
                $q = imagecolorat($b, $x, $y);

                if ($p !== $q && max(abs(($p >> 16 & 255) - ($q >> 16 & 255)), abs(($p >> 8 & 255) - ($q >> 8 & 255)), abs(($p & 255) - ($q & 255))) > 8) {
                    return true;
                }
            }
        }

        return false;
    };

    // Saves what the window shows to docs/screenshots/{name}.png, keeping the
    // picture already there when nothing visible changed, so a run with the
    // same input changes no file.
    $this->shoot = function (mixed $page, string $name): void {
        ($this->settle)($page);
        $file = 'demo-'.str_replace(['/', '.'], '-', $name);
        $page->screenshot(false, $file);

        $taken = base_path("tests/Browser/Screenshots/{$file}.png");
        $kept = "{$this->out}/{$name}.png";
        File::ensureDirectoryExists(dirname($kept));
        ($this->differ)($taken, $kept) ? File::move($taken, $kept) : File::delete($taken);
    };

    $this->signIn = fn (string $scheme, User $person): mixed => (($scheme === 'dark' ? visit('/admin/login')->inDarkMode() : visit('/admin/login')->inLightMode())
        ->resize(1440, 900)
        ->type('email', (string) $person->email)
        ->type('password', DemoSite::PASSWORD)
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin'));

    $this->public = fn (string $scheme, string $url, int $width = 1440, int $height = 900): mixed => ($scheme === 'dark' ? visit($url)->inDarkMode() : visit($url)->inLightMode())->resize($width, $height);
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
    File::deleteDirectory($this->mediaRoot);
});

it('pictures the admin and the public site from the demo content', function (): void {
    $article = $this->site['articles']['village'];
    $edited = $this->site['articles']['bakery'];
    $author = User::query()->findOrFail($edited->author_id);
    $admin = User::query()->findOrFail($this->site['admin']->id);

    foreach (['light' => '', 'dark' => '-dark'] as $scheme => $suffix) {
        // The public site, in the default theme.
        $page = ($this->public)($scheme, '/');
        ($this->shoot)($page, "home{$suffix}");

        foreach ([[1440, 900, 'article'], [390, 844, 'article-phone']] as [$width, $height, $name]) {
            $page = ($this->public)($scheme, (string) Addresses::relativeUrl($article), $width, $height);
            ($this->settle)($page);
            // The title and its picture, with the site's header just out of view.
            $page->script("() => { const top = document.querySelector('main h1').closest('header') ?? document.querySelector('main h1'); window.scrollTo(0, Math.max(0, top.getBoundingClientRect().top + window.scrollY - 32)); }");
            ($this->shoot)($page, "{$name}{$suffix}");
        }

        if ($scheme === 'light') {
            $page = ($this->public)($scheme, (string) Addresses::relativeUrl($this->site['categories']['Travel']));
            ($this->shoot)($page, 'category');
        }

        // The writing screens, as the article's author: an administrator's
        // screens open with the new site's backups notice.
        $page = ($this->signIn)($scheme, $author);

        cmNavigate($page, "/admin/content/{$edited->id}/edit");
        cmEditorSettled($page);
        ($this->shoot)($page, "editor{$suffix}");

        cmNavigate($page, '/admin/posts');
        cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($article->title).')');
        ($this->shoot)($page, "posts{$suffix}");

        cmNavigate($page, '/admin/media');
        cmWaitUntil($page, 'document.querySelectorAll("img").length >= 8');
        ($this->shoot)($page, "media{$suffix}");

        $page = ($this->signIn)($scheme, $admin);

        cmNavigate($page, '/admin/themes');
        cmWaitUntil($page, 'document.body.innerText.includes("Teme Default")');
        ($this->shoot)($page, "design{$suffix}");
    }

    // A plugin with an area of its own: enabled, with three ads made through its form's Operation.
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    PluginFixtures::use(PluginFixtures::copy('placard', ['description' => 'Keeps the site’s ads in an area of its own: an overview, a table of ads and a form for each one.']));
    $manifest = PluginFixtures::manifest('placard');
    app()->instance(Elevation::class, new OperatorElevation);
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest['version'], $manifest['capabilities'], [], [], (string) $admin->id));
    app()->forgetInstance(Elevation::class);
    app(PluginLoader::class)->load();

    foreach (['Autumn market guide', 'Ferry timetable', 'Woodshop open day'] as $name) {
        app(SavePluginRecord::class)->execute(new SavePluginRecordInput('placard', 'ad', null, [
            'name' => $name,
            'location' => 'between_items',
            'versions' => [['label' => 'Wide', 'code' => '<div>'.$name.'</div>', 'weight' => '1']],
            'types' => [],
            'terms' => [],
        ], (string) $admin->id));
    }

    // Warm the screens before the browser's first visit.
    $this->actingAs($admin)->get('/admin/plugins')->assertOk();
    $this->actingAs($admin)->get('/admin/plugin/placard')->assertOk();

    foreach (['light' => '', 'dark' => '-dark'] as $scheme => $suffix) {
        $page = ($this->signIn)($scheme, $admin);

        cmNavigate($page, '/admin/plugins');
        cmWaitUntil($page, 'document.body.innerText.includes("Placard")');
        ($this->shoot)($page, "plugins{$suffix}");

        cmNavigate($page, '/admin/plugin/placard/ads');
        cmWaitUntil($page, 'document.body.innerText.includes("Woodshop open day")');
        ($this->shoot)($page, "plugin-area{$suffix}");
    }
})->group('screenshots');

/*
 * Every screen the editors' and operators' guides point at, in both schemes,
 * as an administrator of the demo site, with docs/screenshots/guides/index.json
 * naming each one's heading and files. The screens that can't be pictured are
 * GuideScreens::SKIPPED, with their reasons.
 */
it('pictures every screen the guides point at', function (): void {
    $admin = User::query()->findOrFail($this->site['admin']->id);
    $article = $this->site['articles']['village'];
    $navigation = Navigation::query()->where('name', 'Primary')->firstOrFail();

    // The screens that need an item or a name open the demo's own.
    $addresses = [
        'content.edit' => route('content.edit', ['content' => $article->id], false),
        'content.compare' => route('content.compare', ['content' => $article->id], false),
        'content.revisions.index' => route('content.revisions.index', ['content' => $article->id], false),
        'content.index' => route('content.index', ['type' => 'posts'], false),
        'terms.index' => route('terms.index', ['taxonomy' => 'categories'], false),
        'navigation.edit' => route('navigation.edit', ['id' => $navigation->id], false),
        'settings.show' => route('settings.show', ['group' => 'general'], false),
    ];

    $routes = array_values(array_diff(GuideScreens::named(), array_keys(GuideScreens::SKIPPED)));
    $index = [];

    // Opens a screen, giving the password where it asks for one, and returns its own heading.
    $open = function (mixed $page, string $name) use ($addresses): string {
        $address = $addresses[$name] ?? route($name, [], false);

        cmNavigate($page, $address);

        if (cmAnswer($page, "() => document.querySelector('[data-test=\"confirm-password-button\"]') !== null", 'cmAnswer') === true) {
            $page->type('#password', DemoSite::PASSWORD)->click('[data-test="confirm-password-button"]');
            cmWaitUntil($page, 'document.querySelector(\'[data-test="confirm-password-button"]\') === null');
        }

        cmWaitUntil($page, "document.querySelector('main h1, h1') !== null && document.querySelector('main h1, h1').innerText.trim() !== ''");
        ($this->settle)($page);

        // What differs from run to run is set to what a real site would show: this run's own address and folder, and the hour's greeting.
        $steady = <<<'JS'
            () => {
                const origin = location.origin;
                const fix = (text) => text.split(origin).join('https://northwind.example').split(__BASE__).join('/var/www/teme').replace(/^Good (afternoon|evening), /, 'Good morning, ');
                const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
                for (let node = walker.nextNode(); node; node = walker.nextNode()) { node.nodeValue = fix(node.nodeValue); }
                document.querySelectorAll('input, textarea').forEach((field) => { field.value = fix(field.value); });
                document.querySelectorAll('a[href]').forEach((link) => { link.setAttribute('href', fix(link.getAttribute('href'))); });
                return true;
            }
            JS;
        cmAnswer($page, str_replace('__BASE__', json_encode(base_path(), JSON_THROW_ON_ERROR), $steady), 'cmAnswer');

        // A new setup key and code are made on every visit; the picture shows fixed ones, the code a pattern that is not a real one, kept as the page redraws.
        if ($name === 'two-factor.edit') {
            cmWaitUntil($page, '/[A-Z2-7]{16,}/.test(document.body.innerText)');
            cmAnswer($page, <<<'JS'
                () => {
                    const patch = () => {
                        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
                        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                            const fixed = node.nodeValue.replace(/^(\s*)[A-Z2-7]{16,}(\s*)$/, '$1NORTHWIND7DEMOKEY$2');
                            if (fixed !== node.nodeValue) { node.nodeValue = fixed; }
                        }
                        const code = [...document.querySelectorAll('main svg')].find((svg) => svg.getBoundingClientRect().width >= 140 && svg.dataset.demo !== 'yes');
                        if (code) {
                            const size = 25;
                            const finder = (x, y) => [[0, 0], [size - 7, 0], [0, size - 7]].some(([a, b]) => x >= a && x < a + 7 && y >= b && y < b + 7);
                            const ring = (x, y) => { const dx = x % (size - 7), dy = y % (size - 7); return dx === 0 || dx === 6 || dy === 0 || dy === 6 || (dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4); };
                            let cells = '';
                            for (let y = 0; y < size; y++) {
                                for (let x = 0; x < size; x++) {
                                    const on = finder(x, y) ? ring(x, y) : ((x * 7 + y * 13 + x * y) % 5 < 2);
                                    if (on) { cells += `<rect x="${x}" y="${y}" width="1" height="1"/>`; }
                                }
                            }
                            code.dataset.demo = 'yes';
                            code.setAttribute('viewBox', `0 0 ${size} ${size}`);
                            code.innerHTML = cells;
                        }
                    };
                    patch();
                    new MutationObserver(patch).observe(document.body, { childList: true, subtree: true, characterData: true });
                    return true;
                }
                JS, 'cmAnswer');
            ($this->settle)($page);
        }

        cmWaitUntil($page, "document.querySelector('main h1, h1') !== null && document.querySelector('main h1, h1').innerText.trim() !== ''");

        return trim((string) cmAnswer($page, "() => (document.querySelector('main h1') ?? document.querySelector('h1')).innerText.replace(/\\s+/g, ' ')", 'cmAnswer'));
    };

    foreach (['light' => '', 'dark' => '-dark'] as $scheme => $suffix) {
        // The sign-in page is pictured signed out; every other screen as the administrator.
        if (in_array('login', $routes, true)) {
            $page = ($this->public)($scheme, route('login', [], false));
            $heading = trim((string) cmAnswer($page, "() => (document.querySelector('main h1') ?? document.querySelector('h1')).innerText.replace(/\\s+/g, ' ')", 'cmAnswer'));
            ($this->shoot)($page, "guides/login{$suffix}");
            $index['login'] = ['label' => $heading, 'light' => 'login.png', 'dark' => 'login-dark.png'];
        }

        $page = ($this->signIn)($scheme, $admin);

        foreach (array_diff($routes, ['login']) as $name) {
            $heading = $open($page, $name);

            if ($name === 'content.edit') {
                cmEditorSettled($page);
            }

            ($this->shoot)($page, "guides/{$name}{$suffix}");
            $index[$name] = ['label' => $heading, 'light' => "{$name}.png", 'dark' => "{$name}-dark.png"];
        }
    }

    ksort($index);
    File::put("{$this->out}/guides/index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
})->group('screenshots');
