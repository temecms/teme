<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

it('still finds a top-level page and a post with one slug when pages hold {parent}', function (): void {
    addrChange($this->tree->admin, ['patterns' => ['post' => '/blog/{slug}/', 'page' => '/{parent}/{slug}/']]);
    addrPublished($this->tree->editor, 'Contact');
    addrPublished($this->tree->editor, 'Contact', 'page');

    expect(fn () => addrChange($this->tree->admin, ['patterns' => ['post' => '/{slug}/', 'page' => '/{parent}/{slug}/']]))
        ->toThrow(fn (ValidationException $exception) => expect(collect($exception->errors())->flatten()->all())->toContain('The post "Contact" and the page "Contact" would both be at /contact/.'));
});

it('leaves a nested page out of that check', function (): void {
    // The post "Team" and the page "Team", under About, share a slug.
    addrChange($this->tree->admin, ['patterns' => ['post' => '/blog/{slug}/', 'page' => '/{parent}/{slug}/']]);

    addrChange($this->tree->admin, ['patterns' => ['post' => '/{slug}/', 'page' => '/{parent}/{slug}/']]);

    expect(addrCurrent('content', $this->tree->teamPost->id))->toBe('team')
        ->and(addrCurrent('content', $this->tree->team->id))->toBe('about/team');
});

it('moves every page to a flat address and back when {parent} is taken out of the pattern and put back', function (): void {
    addrRename($this->tree->teamPost, 'team-post', $this->tree->editor);

    addrChange($this->tree->admin, ['patterns' => ['page' => '/{slug}/']]);

    expect(addrCurrent('content', $this->tree->team->id))->toBe('team')
        ->and(addrCurrent('content', $this->tree->people->id))->toBe('people')
        ->and(addrGet('/about/team/people/')->getStatusCode())->toBe(301);

    addrChange($this->tree->admin, ['patterns' => ['page' => '/{parent}/{slug}/']]);

    expect(addrCurrent('content', $this->tree->team->id))->toBe('about/team')
        ->and(addrCurrent('content', $this->tree->people->id))->toBe('about/team/people')
        ->and(DB::table('paths')->where('target_id', $this->tree->people->id)->orderBy('path')->pluck('path')->all())->toBe(['about/team/people', 'people']);
});
