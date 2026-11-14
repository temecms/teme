<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('saves new settings and recomputes every address in the background', function (): void {
    Queue::fake();

    addrChange($this->admin, ['patterns' => ['post' => '/{year}/{month}/{slug}/']]);

    expect(app(SettingsReader::class)->string('addresses', 'pattern_post'))->toBe('/{year}/{month}/{slug}/');
    Queue::assertPushed(RecomputeAddresses::class, fn (RecomputeAddresses $job): bool => $job->actor->userId === (string) $this->admin->id);
});

it('moves every post to its new address and keeps the old one as a redirect', function (): void {
    $post = addrPublished($this->admin, 'Moving day');

    addrChange($this->admin, ['patterns' => ['post' => '/{year}/{slug}/']]);

    // The year comes from the site's timezone setting, which Addresses reads; the test only
    // checks the shape, so it holds on either side of midnight on 31 December.
    expect(addrCurrent('content', $post->id))->toMatch('#^\d{4}/moving-day$#')
        ->and(DB::table('paths')->where('path', 'moving-day')->value('is_current'))->toBeFalse();
});

it('refuses an empty category base when a category would land on a post\'s address, naming both', function (): void {
    taxonomyTerm('category', 'News');
    addrPublished($this->admin, 'News');

    expect(fn () => addrChange($this->admin, ['bases' => ['category' => '']]))
        ->toThrow(ValidationException::class, '/news/');
});

it('refuses a pattern with an unknown token', function (): void {
    expect(fn () => addrChange($this->admin, ['patterns' => ['post' => '/{postname}/']]))->toThrow(ValidationException::class);
});

it('writes nothing when the person may not manage addresses', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => addrChange($editor, ['bases' => ['tag' => 'topic']]))->toThrow(AuthorizationException::class);
    expect(app(SettingsReader::class)->string('addresses', 'base_tag'))->toBe('tag');
});

it('opens Settings › Addresses for an administrator, and refuses an editor', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/addresses')
            ->where('types.0.key', 'post')
            ->where('types.0.pattern', '/{slug}/')
            ->where('taxonomies.0.base', 'category')
            ->where('authorBase', 'author')
            ->missing('frontShows'));

    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/settings/addresses')->assertForbidden();
});

it('rebuilds nothing for someone who may not move addresses', function (): void {
    $post = addrPublished($this->admin, 'Stays put');
    DB::table('paths')->where('target_id', $post->id)->delete();
    $author = User::factory()->create()->assignRole('author');

    expect(fn () => app(RebuildPaths::class)->execute(new RebuildPathsInput('content', [$post->id], (string) $author->id)))
        ->toThrow(AuthorizationException::class);
    expect(addrCurrent('content', $post->id))->toBeNull();
});

it('refuses a pattern whose addresses would start /_teme/, which core answers (1.7c)', function (): void {
    expect(fn () => addrChange($this->admin, ['patterns' => ['post' => '/_teme/{slug}/']]))
        ->toThrow(ValidationException::class, '/_teme/');
});

it('finds no targets of a kind this release doesn’t know to recompute, never reading accounts for it', function (): void {
    Queue::fake();
    Cache::put(RecomputeAddresses::PROGRESS, ['done' => 0, 'total' => 0, 'running' => true], now()->addDay());

    // A kind a later release adds: RebuildPaths would refuse it, so no IDs may reach it.
    (new RecomputeAddresses(JobActor::person($this->admin->id), kind: 'gadget'))->handle();

    expect(Cache::get(RecomputeAddresses::PROGRESS)['done'] ?? null)->toBe(0);
});
