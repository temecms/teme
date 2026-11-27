<?php

/**
 * The site icon in the public head (issue 388): the design setting wins over
 * the theme's default; favicon sizes are queued, never made during a page
 * view; plugins may not add icon links.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\PublishDesign;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaVariants;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Insertions\HeadCleaner;
use App\Domain\Site\Queries\SiteWebManifest;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
});

afterEach(function (): void {
    if (isset($this->iconThemeParent) && is_string($this->iconThemeParent)) {
        File::deleteDirectory($this->iconThemeParent);
    }
});

/**
 * A theme with a site_icon file in its public folder.
 */
function iconTheme(string $file = 'icon.svg'): string
{
    $source = base_path('tests/Fixtures/themes/good');
    $parent = sys_get_temp_dir().'/teme-icon-'.bin2hex(random_bytes(4));
    $folder = $parent.'/iconic';
    File::copyDirectory($source, $folder);
    File::ensureDirectoryExists($folder.'/public');

    if (str_ends_with($file, '.svg')) {
        file_put_contents($folder.'/public/'.$file, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" fill="#b3261e"/></svg>');
    } else {
        imagepng(imagecreatetruecolor(64, 64), $folder.'/public/'.$file);
    }

    $manifest = json_decode((string) file_get_contents($folder.'/teme-theme.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['code'] = 'iconic';
    $manifest['name'] = 'Iconic';
    $manifest['site_icon'] = $file;
    file_put_contents($folder.'/teme-theme.json', (string) json_encode($manifest));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('iconic');

    test()->iconThemeParent = $parent;

    return $folder;
}

/** Publishes design.site_icon through the design Operations. */
function publishSiteIcon(User $actor, string $mediaId): void
{
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        'design' => ['site_icon' => $mediaId],
    ], (string) $actor->id));
    app(PublishDesign::class)->execute(new PublishDesignInput((string) $actor->id));
}

it('accepts a site_icon path inside the theme\'s public folder', function (): void {
    $path = base_path('tests/Fixtures/themes/good');

    expect(app(ManifestValidator::class)->errors(array_replace(designManifest([]), [
        'site_icon' => 'icon.svg',
    ]), $path))->toBe([])
        ->and(implode("\n", app(ManifestValidator::class)->errors(array_replace(designManifest([]), [
            'site_icon' => '../escape.svg',
        ]), $path)))->toContain('site_icon');
});

it('queues favicon sizes when the site icon is published, and a page view writes nothing', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    iconTheme('icon.svg');
    $picture = pageImage($this->admin, 512, 512);

    expect($picture->refresh()->variants)->not->toHaveKey('icon-32');

    Queue::fake();
    publishSiteIcon($this->admin, $picture->id);

    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->mediaId === $picture->id);

    $before = $picture->refresh()->only(['variants', 'updated_at']);
    addrPublished($this->admin, 'Harbour');
    addrGet('/harbour/')->assertOk();

    expect($picture->refresh()->only(['variants', 'updated_at']))->toEqual($before);
});

it('links the setting\'s icon once sized, the theme default when none, and the setting over the theme', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    iconTheme('icon.svg');
    addrPublished($this->admin, 'Harbour');

    $html = addrGet('/harbour/')->assertOk()->getContent();

    expect($html)->toContain('rel="icon"')
        ->and($html)->toContain('themes/iconic/icon.svg');

    $picture = pageImage($this->admin, 512, 512);
    publishSiteIcon($this->admin, $picture->id);

    expect($picture->refresh()->variants)->toHaveKey('icon-32')
        ->and($picture->variants)->toHaveKey('icon-180')
        ->and($picture->variants)->toHaveKey('icon-192')
        ->and($picture->variants)->toHaveKey('icon-512');

    $html = addrGet('/harbour/')->assertOk()->getContent();

    expect($html)->toContain('sizes="32x32"')
        ->and($html)->toContain('apple-touch-icon')
        ->and($html)->not->toContain('themes/iconic/icon.svg')
        ->and($html)->toContain('rel="manifest"');
});

it('answers the web manifest with 192 and 512 icons, and none when no PNG sizes exist', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');

    expect(app(SiteWebManifest::class)->data()['icons'])->toBe([]);

    $picture = pageImage($this->admin, 512, 512);
    publishSiteIcon($this->admin, $picture->id);

    $icons = app(SiteWebManifest::class)->data()['icons'];

    expect($icons)->toHaveCount(2)
        ->and(array_column($icons, 'sizes'))->toBe(['192x192', '512x512'])
        ->and(array_column($icons, 'type'))->toBe(['image/webp', 'image/webp']);

    $response = $this->get(route('site-webmanifest'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json; charset=UTF-8');
});

it('purges a page that shows the site icon when that image is trashed', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->folder = cacheOn();
    $picture = pageImage($this->admin, 512, 512);
    publishSiteIcon($this->admin, $picture->id);
    addrPublished($this->admin, 'Harbour');
    addrGet('/harbour/')->assertOk();

    cacheShown(['/harbour/' => ['media:'.$picture->id], '/other/' => ['content:unrelated']]);
    $trash = fn () => app(TrashMedia::class)->execute(new MediaActionInput($picture->id, (string) $this->admin->id));

    cacheRolledBack($trash);

    expect(cacheKept('/harbour/', '/other/'))->toHaveCount(2);

    $trash();

    expect(cacheKept('/harbour/', '/other/'))->toBe(['/other/']);

    cacheOff($this->folder);
});

it('lets core write icon and apple-touch-icon, and HeadCleaner drops a plugin\'s', function (): void {
    expect((new LinkTag(LinkRelation::Icon, 'https://example.test/icon.png', sizes: '32x32'))->html())
        ->toContain('rel="icon"')
        ->and((new LinkTag(LinkRelation::AppleTouchIcon, 'https://example.test/apple.png'))->html())
        ->toContain('rel="apple-touch-icon"');

    $cleaned = HeadCleaner::clean(
        '<link rel="icon" href="https://evil.example/x.ico"><link rel="apple-touch-icon" href="https://evil.example/a.png"><link rel="preconnect" href="https://fonts.example">'
    );

    expect($cleaned['html'])->not->toContain('rel="icon"')
        ->and($cleaned['html'])->not->toContain('apple-touch-icon')
        ->and($cleaned['html'])->toContain('preconnect');
});

it('names every MediaVariants icon size', function (): void {
    expect(array_keys(MediaVariants::ICONS))->toBe(['icon-32', 'icon-180', 'icon-192', 'icon-512']);
});
