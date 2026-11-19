<?php

/**
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Dashboard\Queries\SiteFigures;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->contributor = User::factory()->create()->assignRole('contributor');
    $this->figures = fn (User $viewer): array => collect(app(SiteFigures::class)->forUser($viewer))->keyBy('key')->all();
});

/** A site's mixed content: two published posts, a page draft, a scheduled and an in-review post. */
function figuresContent(User $writer, User $reviewer): void
{
    listsPublish(lifecycleDraft($writer, 'Night market'), $reviewer);
    listsPublish(lifecycleDraft($reviewer, 'Harbour news', 'page'), $reviewer);
    lifecycleDraft($writer, 'Draft one');
    lifecycleDraft($reviewer, 'Draft page', 'page');
    schedFor(lifecycleDraft($writer, 'Later'), $reviewer, now()->addDay()->toIso8601String());
    schedSubmit(lifecycleDraft($writer, 'Please check'), $writer);
}

it('gives an administrator five figures across every listed type, in order, with their dots', function (): void {
    figuresContent($this->author, $this->editor);
    cmComment(cmItem($this->editor, 'Market day'), 'pending');

    $figures = collect(app(SiteFigures::class)->forUser($this->admin));

    expect($figures->pluck('key')->all())->toBe(['published', 'drafts', 'scheduled', 'review', 'comments.waiting'])
        ->and($figures->pluck('label')->all())->toBe(['Published', 'Drafts', 'Scheduled', 'In review', 'Comments waiting'])
        ->and($figures->pluck('dot')->all())->toBe(['ok', 'neutral-dot', 'info', 'warn', 'accent'])
        // Two posts and a page published, plus the comment's own post.
        ->and($figures->pluck('value', 'key')->all())->toBe(['published' => 3, 'drafts' => 2, 'scheduled' => 1, 'review' => 1, 'comments.waiting' => 1])
        ->and($figures->first()['url'])->toBe(route('content.index', ['type' => 'posts', 'tab' => 'published']))
        ->and($figures->firstWhere('key', 'review')['url'])->toBe(route('content.index', ['type' => 'posts', 'tab' => 'review']))
        ->and($figures->firstWhere('key', 'comments.waiting')['url'])->toBe(route('comments.index', ['view' => 'pending']));
});

it('leaves media out of the figures', function (): void {
    expect(array_column(app(SiteFigures::class)->forUser($this->admin), 'key'))->not->toContain('media');
});

it('counts only the types an author may list', function (): void {
    figuresContent($this->author, $this->editor);
    app(ContentTypeRegistry::class)->register(new ContentType('ledger', 'Ledger', 'Ledgers', 'ledgers', abilities: ['create' => 'types.manage']));
    lifecycleDraft($this->admin, 'Books', 'ledger');

    $figures = ($this->figures)($this->author);

    // The author may list posts only: no page, and not the ledger's draft, is counted.
    expect($figures['drafts']['value'])->toBe(1)
        ->and($figures['published']['value'])->toBe(1)
        ->and(($this->figures)($this->admin)['drafts']['value'])->toBe(3);
});

it('leaves out the comments figure while comments are off', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    expect(array_column(app(SiteFigures::class)->forUser($this->admin), 'key'))->toBe(['published', 'drafts', 'scheduled', 'review']);
});

it('stays five figures however many types the site has', function (): void {
    foreach (['recipe', 'event', 'movie'] as $key) {
        app(ContentTypeRegistry::class)->register(new ContentType($key, ucfirst($key), ucfirst($key).'s', $key.'s'));
        lifecycleDraft($this->admin, "A {$key}", $key);
    }

    $figures = ($this->figures)($this->admin);

    expect($figures)->toHaveCount(5)
        ->and($figures['drafts']['value'])->toBe(3);
});

it('links a figure only where the viewer may open the list', function (): void {
    expect(($this->figures)($this->contributor)['published']['url'])->toBe(route('content.index', ['type' => 'posts', 'tab' => 'published']));

    $viewer = User::factory()->create();

    expect(($this->figures)($viewer)['published']['url'])->toBeNull();
});
