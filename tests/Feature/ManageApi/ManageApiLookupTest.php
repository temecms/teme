<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ManageApi\ManageApiFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->person = User::factory()->create(['name' => 'Ada Writer'])->assignRole('author');
    ManageApiFixture::on();
    $this->secret = ManageApiFixture::key($this->person, ['site.read']);
});

it('lists a taxonomy’s terms with their ID, name, slug and parent', function (): void {
    $news = taxonomyTerm('category', 'News');
    $local = taxonomyTerm('category', 'Local', $news->id);
    taxonomyTerm('tag', 'Elsewhere');

    $response = $this->getJson('/api/manage/v1/taxonomies/category/terms', ManageApiFixture::headers($this->secret));

    $response->assertOk();

    expect(collect($response->json('data'))->keyBy('name')->only(['News', 'Local'])->all())->toBe([
        'Local' => ['id' => $local->id, 'name' => 'Local', 'slug' => 'local', 'parent' => $news->id],
        'News' => ['id' => $news->id, 'name' => 'News', 'slug' => 'news', 'parent' => null],
    ])->and(collect($response->json('data'))->pluck('name'))->not->toContain('Elsewhere');
});

it('answers not_found for a taxonomy the site doesn’t have', function (): void {
    $this->getJson('/api/manage/v1/taxonomies/flavours/terms', ManageApiFixture::headers($this->secret))
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

it('lists the people an item may be credited to, with their ID, name and slug', function (): void {
    $suspended = User::factory()->create(['name' => 'Gone Away', 'suspended_at' => now()])->assignRole('author');
    User::factory()->create(['name' => 'Just Reading']);

    $response = $this->getJson('/api/manage/v1/people', ManageApiFixture::headers($this->secret));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');

    expect($names)->toContain('Ada Writer')
        ->not->toContain('Gone Away')
        ->not->toContain('Just Reading')
        ->and(collect($response->json('data'))->firstWhere('name', 'Ada Writer'))->toBe([
            'id' => $this->person->id,
            'name' => 'Ada Writer',
            'slug' => $this->person->slug,
        ]);
});

it('needs site.read for both', function (): void {
    $secret = ManageApiFixture::key($this->person, ['media.write'], name: 'Media only');

    $this->getJson('/api/manage/v1/people', ManageApiFixture::headers($secret))
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
    $this->getJson('/api/manage/v1/taxonomies/category/terms', ManageApiFixture::headers($secret))
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
});
