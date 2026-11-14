<?php

declare(strict_types=1);

use App\Domain\Design\Operations\CreateDesignPreview;
use App\Domain\Design\Operations\Input\CreateDesignPreviewInput;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\PublishDesign;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('shows draft design values on a signed preview link, and live values without it', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['accent' => '#0b5394']], (string) $this->admin->id));
    $url = app(CreateDesignPreview::class)->execute(new CreateDesignPreviewInput('/', (string) $this->admin->id));

    addrGet($url)->assertOk()
        ->assertSee('--teme-accent:#0b5394', false)
        ->assertSee('Previewing unpublished design changes')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    addrGet('/')->assertOk()->assertDontSee('--teme-accent:#0b5394', false);
});

it('refuses an expired or tampered preview link', function (): void {
    $url = app(CreateDesignPreview::class)->execute(new CreateDesignPreviewInput('/', (string) $this->admin->id));

    addrGet($url.'x')->assertForbidden();
    $this->travel(61)->minutes();
    addrGet($url)->assertForbidden();
});

it('refuses a preview link for an outside address, or to someone who may not manage the design', function (): void {
    $create = fn (string $path, User $actor): string => app(CreateDesignPreview::class)->execute(new CreateDesignPreviewInput($path, (string) $actor->id));

    expect(fn () => $create('https://evil.example/', $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $create('//evil.example/', $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $create('/', User::factory()->create()->assignRole('editor')))->toThrow(AuthorizationException::class);
});

it('previews a theme switch', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/quiet')]]);
    themeActivate('good');
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'quiet']], (string) $this->admin->id));
    $url = app(CreateDesignPreview::class)->execute(new CreateDesignPreviewInput('/', (string) $this->admin->id));

    addrGet($url)->assertOk()->assertSee('themes/quiet/', false)->assertDontSee('speculationrules', false);
    addrGet('/')->assertOk()->assertSee('themes/good/', false);
});

it('reads the theme\'s colour scheme and featured-image style by name', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    pageArticle($this->admin, 'Pictured', featured: pageImage($this->admin, 1600, 900));

    expect(addrGet('/pictured/')->assertOk()->getContent())->toContain('data-scheme="auto"')->toContain('fetchpriority="high"');

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['color_scheme' => 'dark', 'featured_image_style' => 'hidden']], (string) $this->admin->id));
    app(PublishDesign::class)->execute(new PublishDesignInput((string) $this->admin->id));

    expect(addrGet('/pictured/')->assertOk()->getContent())->toContain('data-scheme="dark"')->not->toContain('fetchpriority="high"');
});
