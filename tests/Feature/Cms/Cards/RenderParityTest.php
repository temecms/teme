<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentRenderer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

dataset('edFixtures', [
    'prose', 'callout', 'snippet', 'button', 'image', 'gallery', 'embed',
    'aligned', 'code-language', 'ordered-start', 'table-align', 'links',
]);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    edFixtureMedia(User::factory()->create()->assignRole('author'));
});

/**
 * The same document, rendered by PHP for the site and by the browser for the
 * editor, must produce the same HTML. Without this the editor slowly starts
 * lying about what will be published.
 */
it('renders every fixture identically in both halves', function (string $name): void {
    $document = json_decode(file_get_contents(base_path("tests/Fixtures/Documents/{$name}.json")), true);

    $php = app(DocumentRenderer::class)->html($document);

    // The browser gets exactly what the editor screen would send it.
    $media = tempnam(sys_get_temp_dir(), 'teme-media');
    file_put_contents($media, json_encode((object) app(CardMedia::class)->forDocument($document)));
    $manifest = tempnam(sys_get_temp_dir(), 'teme-manifest');
    file_put_contents($manifest, json_encode(CardManifest::forBrowser(app(CardRegistry::class))));

    $node = Process::timeout(120)->run([
        'node', base_path('tests/Fixtures/render.mjs'), base_path("tests/Fixtures/Documents/{$name}.json"), $media, $manifest,
    ]);
    unlink($media);
    unlink($manifest);

    expect($node->successful())->toBeTrue($node->errorOutput())
        ->and(edNormalise($node->output()))->toBe(edNormalise($php));
})->with('edFixtures');

it('accepts every fixture on the server, as the editor would write it', function (string $name): void {
    docValidate(json_decode(file_get_contents(base_path("tests/Fixtures/Documents/{$name}.json")), true));
})->with('edFixtures')->throwsNoExceptions();
