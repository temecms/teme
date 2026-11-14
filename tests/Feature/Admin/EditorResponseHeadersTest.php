<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

uses(RefreshDatabase::class);

/*
 * A full load of the editor answers with headers nginx's FastCGI buffer must
 * hold, or the browser gets a 502 ("upstream sent too big header"). The
 * build is a stand-in manifest with far more chunks than any real one, so a
 * header that grows with the chunk count fails here long before a deploy.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = lifecycleDraft($this->author, 'Harbour news');

    $this->publicPath = sys_get_temp_dir().'/teme-header-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->publicPath.'/build');

    $chunks = [];
    $names = [];

    for ($i = 1; $i <= 150; $i++) {
        $chunks["_chunk{$i}-4f3c2a1b.js"] = ['file' => "assets/chunk-{$i}-4f3c2a1b.js"];
        $names[] = "_chunk{$i}-4f3c2a1b.js";
    }

    $manifest = $chunks + [
        'resources/css/app.css' => ['file' => 'assets/app-4f3c2a1b.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        'resources/js/app.tsx' => ['file' => 'assets/app-4f3c2a1b.js', 'src' => 'resources/js/app.tsx', 'isEntry' => true, 'imports' => $names],
        'resources/js/pages/content/edit.tsx' => ['file' => 'assets/edit-4f3c2a1b.js', 'src' => 'resources/js/pages/content/edit.tsx', 'isEntry' => true, 'imports' => $names],
    ];

    File::put($this->publicPath.'/build/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $this->withVite();
    app()->usePublicPath($this->publicPath);
});

afterEach(function (): void {
    File::deleteDirectory($this->publicPath);
});

it('keeps a full load of the editor\'s headers within what nginx\'s FastCGI buffer holds', function (): void {
    $response = $this->actingAs($this->author)->get("/admin/content/{$this->item->id}/edit")->assertOk();

    $size = 0;
    foreach ($response->headers->allPreserveCase() as $name => $values) {
        foreach ($values as $value) {
            $size += strlen($name) + strlen((string) $value) + 4;
        }
    }

    // The page itself still preloads its chunks, as <link rel="modulepreload"> tags.
    expect($response->getContent())->toContain('rel="modulepreload"')
        ->and(Vite::preloadedAssets())->not->toBe([])
        ->and(strlen((string) $response->headers->get('Link')))->toBeLessThan(4096)
        ->and($size)->toBeLessThan(8192);
});
