<?php

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Queries\MediaLibrary;
use App\Domain\Media\Queries\MediaLibraryRequest;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Images\ImageServiceDeclaration;
use App\Domain\Plugins\Images\ImageServices;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seo\SeoDeclarations;
use App\Domain\Plugins\Seo\SocketAnswerRefused;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Tests\Fixtures\Plugins\Imagery\Imagery;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Policy\PolicyHeaders;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/imagery/src/Imagery.php';

uses(RefreshDatabase::class);

/**
 * The faults recorded while a test runs, kept in order.
 */
final class ImageServiceFaults implements ListenerFaults
{
    /** @var list<array{string, string, string, Throwable}> */
    public array $seen = [];

    public function record(string $owner, string $id, string $hook, Throwable $failure): void
    {
        $this->seen[] = [$owner, $id, $hook, $failure];
    }
}

/**
 * Enables and loads Imagery, agreeing to what it asks, with the faults a
 * test can read.
 */
function imageryEnabled(): ImageServiceFaults
{
    Imagery::reset();
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $faults = new ImageServiceFaults;
    app()->instance(ListenerFaults::class, $faults);

    PluginFixtures::use('imagery');
    $manifest = app(PluginRegistry::class)->find('tests/imagery')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/imagery', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(ImageServices::class);

    return $faults;
}

/**
 * The page's HTML.
 */
function imageryPage(string $uri): string
{
    return (string) addrGet($uri)->assertOk()->getContent();
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')], 'app.url' => 'http://localhost']);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->image = pageImage($this->author, 1600, 900);
    $this->carded = fn (Media $image): string => pageArticleFromDocument($this->author, 'Harbour picture', [
        pageImageNode($image, (string) Str::ulid()),
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Boats.']]],
    ])->id;
});

afterEach(function (): void {
    Imagery::reset();
    PluginFixtures::forget();
});

it('keeps core’s addresses, the sizes made at upload, with no image service', function (): void {
    ($this->carded)($this->image);
    $made = MediaDisks::public()->url($this->image->refresh()->variants['w960']['path']);

    expect(imageryPage('/harbour-picture/'))->toContain($made)->not->toContain('images.example.com')
        ->and(app(MediaLibrary::class)->page(new MediaLibraryRequest)['rows'][0]['thumbUrl'])->toBe(MediaDisks::public()->url($this->image->variants['thumb']['path']));
});

it('draws an image card with the driver’s addresses, asking for each size and its format', function (): void {
    imageryEnabled();
    ($this->carded)($this->image);
    $id = $this->image->id;

    expect(imageryPage('/harbour-picture/'))->toContain("https://images.example.com/{$id}/w960.webp")
        ->and(Imagery::$asked)->toContain([$id, 'w320', 'webp'], [$id, 'w960', 'webp']);
});

it('draws a featured image with the driver’s address', function (): void {
    imageryEnabled();
    pageArticle($this->author, 'Pictured', featured: $this->image);

    expect(imageryPage('/pictured/'))->toContain("https://images.example.com/{$this->image->id}/w");
});

it('gives the media library’s thumbnails the driver’s address', function (): void {
    imageryEnabled();

    expect(app(MediaLibrary::class)->page(new MediaLibraryRequest)['rows'][0]['thumbUrl'])->toBe("https://images.example.com/{$this->image->id}/thumb.webp");
});

it('keeps a site-relative path the driver gives', function (): void {
    imageryEnabled();
    Imagery::$address = fn (string $id, string $variant): string => "/img/{$id}/{$variant}";

    expect(app(MediaLibrary::class)->page(new MediaLibraryRequest)['rows'][0]['thumbUrl'])->toBe("/img/{$this->image->id}/thumb");
});

it('refuses an address that isn’t https on the declared host or a path on this site, records it once, and uses core’s', function (string $address): void {
    $faults = imageryEnabled();
    Imagery::$address = fn (): string => $address;
    ($this->carded)($this->image);
    $made = MediaDisks::public()->url($this->image->refresh()->variants['w960']['path']);

    expect(imageryPage('/harbour-picture/'))->toContain($made)->not->toContain($address)
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/imagery#image-service'])
        ->and($faults->seen[0][3])->toBeInstanceOf(SocketAnswerRefused::class);
})->with([
    'plain http' => ['http://images.example.com/a.webp'],
    'another host' => ['https://elsewhere.example.org/a.webp'],
    'a port' => ['https://images.example.com:8443/a.webp'],
    'a user' => ['https://me@images.example.com/a.webp'],
    'no scheme' => ['//images.example.com/a.webp'],
    'a script' => ['javascript:alert(1)'],
    'a quote' => ['/img/"onerror=alert(1)'],
]);

it('uses core’s address where the driver gives none or fails', function (): void {
    $faults = imageryEnabled();
    Imagery::$address = fn (): ?string => null;
    $thumb = MediaDisks::public()->url($this->image->variants['thumb']['path']);

    expect(app(MediaLibrary::class)->page(new MediaLibraryRequest)['rows'][0]['thumbUrl'])->toBe($thumb)
        ->and($faults->seen)->toBe([]);

    app()->forgetInstance(ImageServices::class);
    Imagery::$address = fn (): string => throw new RuntimeException('Down');

    expect(app(MediaLibrary::class)->page(new MediaLibraryRequest)['rows'][0]['thumbUrl'])->toBe($thumb)
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/imagery#image-service']);
});

it('adds the service’s origin to the page’s content security policy, and the admin’s', function (): void {
    imageryEnabled();
    config(['teme.public_csp' => 'enforce']);
    ($this->carded)($this->image);

    expect(PolicyHeaders::sources(PolicyHeaders::of(addrGet('/harbour-picture/')->assertOk()), 'img-src'))->toContain('https://images.example.com')
        ->and(app(ImageServices::class)->origin())->toBe('https://images.example.com');

    $admin = User::factory()->create()->assignRole('administrator');
    $policy = (string) $this->actingAs($admin)->get('/admin/media')->headers->get('Content-Security-Policy');

    expect(PolicyHeaders::sources($policy, 'img-src'))->toContain('https://images.example.com');
});

it('names the service and its host in what the person agrees to', function (): void {
    PluginFixtures::use('imagery');
    $manifest = app(PluginRegistry::class)->find('tests/imagery')?->manifest;

    expect(SeoDeclarations::consentWords($manifest))->toContain('Give your images’ addresses from Imagery CDN, at images.example.com, in place of the sizes made at upload.')
        ->and(SeoDeclarations::consentWords($manifest, []))->toBe([]);
});

it('refuses an image service without media.read, or not well formed', function (): void {
    $validator = app(ContributionsValidator::class);

    expect($validator->errors(['imageService' => ['label' => 'CDN', 'host' => 'images.example.com']], '1.0.0', ['pages.head']))
        ->toBe(['It adds an image service, but the plugin doesn’t ask for media.read.'])
        ->and($validator->errors(['imageService' => ['label' => '', 'host' => 'https://images.example.com']], '1.0.0', ['media.read']))->toHaveCount(2)
        ->and($validator->errors(['imageService' => ['label' => 'CDN', 'host' => 'images.example.com', 'secret' => 'x']], '1.0.0', ['media.read']))->toHaveCount(1)
        ->and($validator->errors(['imageService' => ['label' => 'CDN', 'host' => '*.example.com']], '1.0.0', ['media.read']))->toBe([]);
});

it('refuses a plugin that declares an image service its class doesn’t hand over, and the reverse', function (): void {
    $plain = new class implements Plugin
    {
        public function register(Hooks $hooks): void {}
    };

    expect(ImageServiceDeclaration::startErrors(['imageService' => ['label' => 'CDN', 'host' => 'images.example.com']], $plain))->toHaveCount(1)
        ->and(ImageServiceDeclaration::startErrors([], new Imagery))->toBe(['It hands over an image service but declares none.'])
        ->and(ImageServiceDeclaration::startErrors(['imageService' => ['label' => 'CDN', 'host' => 'images.example.com']], new Imagery))->toBe([]);
});
