<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginHttp;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Media\MediaWriter;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Http::preventStrayRequests();
    app()->instance(HostResolver::class, FakeResolver::answering([
        'cdn.example.com' => ['93.184.215.14'],
        'private.example.com' => ['10.0.0.5'],
    ]));

    $this->temps = [];
    $this->door = fn (array $hosts = ['cdn.example.com']): MediaWriter => app(PluginDoors::class)
        ->for('tests/writer', 'writer', ['media.write'], $hosts)[MediaWriter::class];

    $this->jpeg = function () use (&$temps): string {
        $path = mediaJpeg(200, 150);
        $this->temps[] = $path;

        return (string) file_get_contents($path);
    };
});

afterEach(function (): void {
    foreach ($this->temps as $path) {
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }
});

it('stores a file from an agreed host, credited to the acting person', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $body = ($this->jpeg)();
    Http::fake(['https://cdn.example.com/harbour.jpg' => Http::response($body, 200, ['Content-Type' => 'image/jpeg'])]);

    $media = ($this->door)()->fromAddress('https://cdn.example.com/harbour.jpg', (string) $editor->id, alt: 'Harbour', title: 'Harbour view');

    expect($media->mimeType)->toBe('image/jpeg')
        ->and($media->filename)->toBe('harbour.jpg')
        ->and(Media::query()->findOrFail($media->id)->uploaded_by)->toBe($editor->id)
        ->and(Media::query()->findOrFail($media->id)->title)->toBe('Harbour view')
        ->and(Media::query()->findOrFail($media->id)->alt)->toBe('Harbour')
        ->and(Media::query()->count())->toBe(1);
});

it('refuses a host that was not agreed to, and makes no request', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    Http::fake();
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://other.example.com/x.jpg', (string) $editor->id))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('refuses a private address and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    Http::fake();
    $before = Media::query()->count();

    expect(fn () => ($this->door)(['private.example.com'])->fromAddress('https://private.example.com/x.jpg', (string) $editor->id))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('refuses a file over the upload limit and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    dlMediaLimits(['max_upload_mb' => 1]);
    Http::fake(['https://cdn.example.com/big.jpg' => Http::response(str_repeat('a', PluginHttp::MOST_BYTES + 1), 200)]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/big.jpg', (string) $editor->id))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
});

it('stores a 2 MB file when the site upload limit is 2 MB', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    dlMediaLimits(['max_upload_mb' => 2]);
    $bytes = 2 * 1024 * 1024;
    // A minimal JPEG header so UploadMedia accepts the type, padded to 2 MB.
    $jpeg = (string) file_get_contents(mediaJpeg(200, 150));
    $body = $jpeg.str_repeat("\0", max(0, $bytes - strlen($jpeg)));
    Http::fake(['https://cdn.example.com/large.jpg' => Http::response($body, 200, ['Content-Type' => 'image/jpeg'])]);

    $media = ($this->door)()->fromAddress('https://cdn.example.com/large.jpg', (string) $editor->id);

    expect(Media::query()->findOrFail($media->id)->size_bytes)->toBe($bytes)
        ->and(Media::query()->count())->toBe(1);
});

it('refuses a type UploadMedia refuses and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $damaged = mediaDamagedGif();
    $this->temps[] = $damaged;
    Http::fake(['https://cdn.example.com/bad.gif' => Http::response((string) file_get_contents($damaged), 200)]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/bad.gif', (string) $editor->id))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
});

it('refuses when no person is named and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    Http::fake(['https://cdn.example.com/x.jpg' => Http::response(($this->jpeg)(), 200)]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/x.jpg', ''))
        ->toThrow(WriteRefused::class, 'A plugin writes on behalf of a person, and none was named.')
        ->and(Media::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('refuses a person without media.upload and writes nothing', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $this->actingAs($contributor);
    Http::fake(['https://cdn.example.com/harbour.jpg' => Http::response(($this->jpeg)(), 200, ['Content-Type' => 'image/jpeg'])]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/harbour.jpg', (string) $contributor->id))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
});

it('refuses an unknown person and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    Http::fake(['https://cdn.example.com/harbour.jpg' => Http::response(($this->jpeg)(), 200)]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/harbour.jpg', '999999'))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('refuses a 3xx answer and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    Http::fake(['https://cdn.example.com/harbour.jpg' => Http::response('moved', 302, ['Location' => 'https://cdn.example.com/other.jpg'])]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/harbour.jpg', (string) $editor->id))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
});

it('strips the query string from the stored source URL', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $body = ($this->jpeg)();
    Http::fake(['https://cdn.example.com/harbour.jpg*' => Http::response($body, 200, ['Content-Type' => 'image/jpeg'])]);

    $media = ($this->door)()->fromAddress('https://cdn.example.com/harbour.jpg?token=secret', (string) $editor->id);

    expect(Media::query()->findOrFail($media->id)->source_url)->toBe('https://cdn.example.com/harbour.jpg');
});

it('refuses alt text over 1000 characters before fetching, and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    Http::fake(['https://cdn.example.com/harbour.jpg' => Http::response(($this->jpeg)(), 200)]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/harbour.jpg', (string) $editor->id, alt: str_repeat('a', 1001)))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('refuses when a contributor’s request asks to act for an administrator, and writes nothing', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $admin = User::factory()->create()->assignRole('administrator');
    $this->actingAs($contributor);
    Http::fake(['https://cdn.example.com/x.jpg' => Http::response(($this->jpeg)(), 200)]);
    $before = Media::query()->count();

    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/x.jpg', (string) $admin->id))
        ->toThrow(WriteRefused::class)
        ->and(Media::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('removes the temporary file after success and after refusal', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $dir = sys_get_temp_dir();
    Http::fake(['https://cdn.example.com/ok.jpg' => Http::response(($this->jpeg)(), 200, ['Content-Type' => 'image/jpeg'])]);

    ($this->door)()->fromAddress('https://cdn.example.com/ok.jpg', (string) $editor->id);

    expect(glob($dir.'/teme-plugin-media-*') ?: [])->toBe([]);

    Http::fake(['https://cdn.example.com/bad.gif' => Http::response((string) file_get_contents(mediaDamagedGif()), 200)]);
    expect(fn () => ($this->door)()->fromAddress('https://cdn.example.com/bad.gif', (string) $editor->id))->toThrow(WriteRefused::class);
    expect(glob($dir.'/teme-plugin-media-*') ?: [])->toBe([]);
});
