<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('saves prose and allowed cards in a region', function (): void {
    $region = regionCreate($this->editor);
    $created = $region->working_revision_id;

    regionSave($region, $this->editor, [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Get the newsletter.']]],
        regionCardNode('button'),
    ]);

    expect($region->refresh()->working_revision_id)->not->toBe($created);
});

it('refuses a card the region does not allow', function (): void {
    $region = regionCreate($this->editor);

    expect(fn () => regionSave($region, $this->editor, [regionCardNode('snippet')]))
        ->toThrow(ValidationException::class, 'can\'t be used in this region');
});

it('holds an autosave to the same limits', function (): void {
    $region = regionCreate($this->editor);

    expect(fn () => edSave($region->id, $this->editor, docWith(regionCardNode('snippet'))))
        ->toThrow(ValidationException::class, 'can\'t be used in this region');
});

it('refuses more blocks than the region takes', function (): void {
    $region = regionCreate($this->editor);
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]];

    expect(fn () => regionSave($region, $this->editor, array_fill(0, 6, $paragraph)))
        ->toThrow(ValidationException::class, 'at most 5 blocks');
});

it('refuses to save a region its theme no longer declares', function (): void {
    $region = regionCreate($this->editor);
    config(['teme.theme_paths' => []]);

    expect(fn () => regionSave($region, $this->editor, [docText('Still here?')]))
        ->toThrow(ValidationException::class, 'no longer declared by its theme');
});

it('refuses to publish what the region\'s declaration no longer allows, and publishes nothing', function (array $narrowed, string $message): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [docText('One'), docText('Two'), regionCardNode('button')]);

    // The theme is updated: the same code, and a narrower region.
    $folder = sys_get_temp_dir().'/teme-regions-'.getmypid().'-'.uniqid().'/good';
    mkdir($folder, 0777, true);
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest(['regions' => ['after-content' => ['label' => 'After the article', ...$narrowed]]])));
    config(['teme.theme_paths' => [$folder]]);

    expect(fn () => listsPublish($region, $this->editor))->toThrow(ValidationException::class, $message);
    expect($region->refresh()->published_revision_id)->toBeNull()
        ->and($region->status)->toBe('draft');

    File::deleteDirectory(dirname($folder));
})->with([
    'fewer blocks' => [['cards' => ['callout', 'button'], 'max' => 2], 'This region now allows at most 2 blocks'],
    'a card taken away' => [['cards' => ['callout'], 'max' => 5], 'This region now doesn\'t allow the "button" card'],
]);
