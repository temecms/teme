<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathLinkResolver;
use App\Domain\Cards\DocumentRenderer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('links to another article at its current address, from the address settings', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $target = addrPublished($this->editor, 'Target story');
    addrChange($admin, ['patterns' => ['post' => '/{year}/{slug}/']]);
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'read this', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$target->id]]]],
    ]]]];

    expect(app(DocumentRenderer::class)->html($doc))->toMatch('#href="/\d{4}/target-story/"#');
});

it('gives the absolute address with its trailing slash', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');

    expect(Addresses::urlFor($about))->toBe(rtrim(url('/'), '/').'/about/');
});

it('leaves the text unlinked when the target has no address', function (): void {
    $draft = lifecycleDraft($this->editor, 'Not published');
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'soon', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$draft->id]]]],
    ]]]];

    expect(app(DocumentRenderer::class)->html($doc))->not->toContain('href=');
});

it('finds the address and the type through both keys, never a scan (1.6h)', function (): void {
    $target = addrPublished($this->editor, 'Target story');
    // Items of several owners, as on a site: with one, contents_author_id_id (I-3366) could answer an ID as cheaply as the key.
    foreach (range(1, 3) as $n) {
        planFillerPosts(User::factory()->create(), 50);
    }

    DB::statement('ANALYZE contents');
    DB::statement('ANALYZE paths');

    // Compared as text alone, the ID would carry over to contents as
    // contents.id::text, which its key can't answer: a scan of every item.
    $plan = planOf(fn () => app(PathLinkResolver::class)->url((string) $target->id), 'join "contents"');

    expect(planReadsIndex($plan, 'paths_one_current', 'paths'))->toBeTrue($plan)
        ->and($plan)->toMatch('/Index Cond: .*\(target_id\)::text = /')
        ->and(planReadsIndex($plan, 'contents_pkey', 'contents'))->toBeTrue($plan)
        ->and($plan)->not->toContain('Seq Scan');
});
