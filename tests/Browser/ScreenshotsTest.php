<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
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
        $page->screenshot(false, "demo-{$name}");

        $taken = base_path("tests/Browser/Screenshots/demo-{$name}.png");
        $kept = "{$this->out}/{$name}.png";
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
