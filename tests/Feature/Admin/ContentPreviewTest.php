<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->other = User::factory()->create(['name' => 'Bea'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');

    // A policy's directives by name: 'img-src' => "img-src 'self' data:".
    $this->directives = fn (string $policy): array => collect(explode('; ', $policy))
        ->mapWithKeys(fn (string $directive): array => [explode(' ', $directive, 2)[0] => $directive])
        ->all();

    // Puts the public media disk at this address, and forgets the disk built from the old one.
    $this->mediaAt = function (string $address): void {
        $disk = (string) config('media.disk');
        config(["filesystems.disks.{$disk}.url" => $address]);
        Storage::forgetDisk($disk);
    };
});

it('shows the latest saved version through the theme, marked, and never indexed or kept', function (): void {
    $content = pageArticle($this->author, 'Published title');
    pageSaveDraft($content, 'Draft title');

    $this->actingAs($this->author)
        ->get(route('content.preview', ['content' => $content->id]))
        ->assertOk()
        ->assertSee('data-template="single"', false)
        ->assertSee('Draft title')
        ->assertSee('Preview of the latest saved version · Visible only to people who can edit it')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('lets an editor preview someone else\'s draft, which they may edit', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music by the quay.')));

    $this->actingAs($this->editor)
        ->get(route('content.preview', ['content' => $content->id]))
        ->assertOk()
        ->assertSee('Three days of music by the quay.');
});

it('refuses someone who may not edit the item, and writes nothing (I-409)', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    $this->actingAs($this->other)
        ->get(route('content.preview', ['content' => $content->id]))
        ->assertForbidden();

    expect(DB::table('preview_links')->count())->toBe(0)
        ->and(DB::table('content_autosaves')->count())->toBe(0)
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions);
});

it('answers 404 for an item in the trash, and for one that does not exist', function (string $case): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    if ($case === 'in the trash') {
        app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));
    }

    $id = $case === 'in the trash' ? $content->id : strtolower((string) Str::ulid());

    $this->actingAs($this->author)
        ->get(route('content.preview', ['content' => $id]))
        ->assertNotFound();
})->with(['in the trash', 'that does not exist']);

it('gives the preview the admin\'s policy with three additions and nothing wider, and every other admin header (I-1324)', function (): void {
    ($this->mediaAt)('https://media.example.test/files');
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $response = $this->actingAs($this->author)
        ->get(route('content.preview', ['content' => $content->id]))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $policy = (string) $response->headers->get('Content-Security-Policy');
    $directives = ($this->directives)($policy);

    expect(array_keys($directives))->toBe(['default-src', 'script-src', 'style-src', 'img-src', 'media-src', 'frame-src', 'font-src', 'connect-src', 'object-src', 'base-uri', 'form-action', 'frame-ancestors'])
        ->and($directives['default-src'])->toBe("default-src 'self'")
        ->and($directives['script-src'])->toMatch("/^script-src 'self' 'nonce-[^']+'$/")
        ->and($directives['img-src'])->toBe("img-src 'self' data: https://temecms.github.io https://media.example.test")
        ->and($directives['media-src'])->toBe("media-src 'self' https://media.example.test")
        ->and($directives['frame-src'])->toBe('frame-src https://www.youtube-nocookie.com https://player.vimeo.com https://open.spotify.com')
        ->and($directives['connect-src'])->toBe("connect-src 'self'")
        ->and($directives['frame-ancestors'])->toBe("frame-ancestors 'none'")
        ->and($policy)->not->toContain('*')
        ->and($policy)->not->toContain("'unsafe-eval'");
});

it('adds no media origin when the media disk is on the admin\'s own origin', function (): void {
    ($this->mediaAt)(url('/storage/media'));
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $directives = ($this->directives)((string) $this->actingAs($this->author)
        ->get(route('content.preview', ['content' => $content->id]))
        ->assertOk()
        ->headers->get('Content-Security-Policy'));

    expect($directives['img-src'])->toBe("img-src 'self' data: https://temecms.github.io")
        ->and($directives['media-src'])->toBe("media-src 'self'");
});

it('leaves every other admin page\'s policy exactly as it was', function (): void {
    ($this->mediaAt)('https://media.example.test/files');
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $admin = "default-src 'self'; script-src 'self' 'nonce-X'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://temecms.github.io; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    // The preview first: what it adds must not stay for the next request.
    $this->actingAs($this->author)->get(route('content.preview', ['content' => $content->id]))->assertOk();

    foreach ([route('content.edit', ['content' => $content->id]), route('content.index', ['type' => 'posts'])] as $address) {
        $policy = (string) $this->actingAs($this->author)->get($address)->assertOk()->headers->get('Content-Security-Policy');

        expect(preg_replace("/'nonce-[^']+'/", "'nonce-X'", $policy))->toBe($admin);
    }
});

it('prints no site code in the preview, though the public page draws it (I-1324)', function (): void {
    $admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $item = cmItem($this->author, 'Harbour news');
    scAdd($admin, 'Analytics', 'head', '<script data-test="site-code-head"></script>');
    scAdd($admin, 'Chat', 'body-end', '<div data-test="site-code-end"></div>');

    // The public page first: in a test, a scoped service outlives one request,
    // and the preview withholds site code for the rest of it.
    expect((string) addrGet('/harbour-news/')->assertOk()->getContent())
        ->toContain('data-test="site-code-head"')
        ->toContain('data-test="site-code-end"');

    expect((string) $this->actingAs($this->author)->get(route('content.preview', ['content' => $item->id]))->assertOk()->getContent())
        ->not->toContain('data-test="site-code-head"')
        ->not->toContain('data-test="site-code-end"');
});

it('sends someone signed out to sign in, and shows them nothing', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $this->get(route('content.preview', ['content' => $content->id]))
        ->assertRedirect()
        ->assertDontSee('Harbour festival');
});
