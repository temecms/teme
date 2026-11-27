<?php

declare(strict_types=1);

use App\Domain\Comments\Checks\CommentChecks;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\ModerationVerdict;
use Tests\Fixtures\Plugins\Checker\Checker;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/checker/src/Checker.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com'])->assignRole('author');
    $this->item = cmItem($this->author);
    Checker::reset();
});

/**
 * The candidate a comment becomes when the controller asks the check about it.
 */
function commentCandidateFor(Content $item, string $text): CommentCandidate
{
    return new CommentCandidate(contentId: $item->id, parentId: null, text: $text, links: 0, authorName: 'Ada Guest', authorEmail: 'ada@example.com', authorUrl: null, authorUserId: null, ip: '203.0.113.7', userAgent: 'Mozilla/5.0 (Test)', returning: false, itemUrl: null);
}

/**
 * Enables the checker plugin as the operator and loads it, so it is the lone comment-check provider.
 */
function checkerEnabled(): void
{
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    PluginFixtures::use('checker');
    $manifest = app(PluginRegistry::class)->find('tests/checker')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/checker', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(CommentChecks::class);
}

it('stops a comment the plugin marks as spam', function (): void {
    checkerEnabled();
    Checker::$verdict = fn (CommentCandidate $comment): ModerationVerdict => new ModerationVerdict(ModerationVerdict::SPAM, 'Looks like spam.');

    $verdict = app(CommentChecks::class)->verdict(commentCandidateFor($this->item, 'Buy now'));
    $submitted = cmSubmit($this->item, 'Buy now', ['checked' => $verdict]);

    expect($submitted->status)->toBe(ModerationVerdict::SPAM)
        ->and(Checker::$asked)->toBe(['Buy now']);
});

it('keeps core’s block list after a plugin approves', function (): void {
    checkerEnabled();
    Checker::$verdict = fn (CommentCandidate $comment): ModerationVerdict => new ModerationVerdict(ModerationVerdict::APPROVED, 'Fine.');

    $verdict = app(CommentChecks::class)->verdict(commentCandidateFor($this->item, 'See https://a.example https://b.example'));
    $submitted = cmSubmit($this->item, 'See https://a.example https://b.example', ['checked' => $verdict]);

    expect($submitted->status)->not->toBe(ModerationVerdict::APPROVED)
        ->and(Comment::query()->findOrFail($submitted->commentId)->status)->not->toBe(ModerationVerdict::APPROVED);
});

it('merges verdicts strictest first, and treats no opinion as none', function (): void {
    $spam = new ModerationVerdict(ModerationVerdict::SPAM, 's');
    $pending = new ModerationVerdict(ModerationVerdict::PENDING, 'p');
    $approved = new ModerationVerdict(ModerationVerdict::APPROVED, 'a');

    expect(CommentChecks::strictest($approved, $spam))->toBe($spam)
        ->and(CommentChecks::strictest($spam, $pending))->toBe($spam)
        ->and(CommentChecks::strictest($pending, $approved))->toBe($pending)
        ->and(CommentChecks::strictest(null, $approved))->toBe($approved)
        ->and(CommentChecks::strictest($approved, null))->toBe($approved);
});

it('asks no plugin when none fills the slot', function (): void {
    expect(app(CommentChecks::class)->verdict(commentCandidateFor($this->item, 'Hello')))->toBeNull();
});

it('skips a check that answers over its budget, and asks it no more in the request', function (): void {
    checkerEnabled();
    $clock = new class implements ColumnClock
    {
        public int $now = 0;

        public function nanoseconds(): int
        {
            return $this->now;
        }
    };
    app()->instance(ColumnClock::class, $clock);
    app()->forgetInstance(SeoPlugins::class);
    app()->forgetInstance(CommentChecks::class);
    // Sleeps past the 200 ms budget on the clock core reads.
    Checker::$verdict = function () use ($clock): ModerationVerdict {
        $clock->now += 300_000_000;

        return new ModerationVerdict(ModerationVerdict::SPAM, 'Late.');
    };

    $first = app(CommentChecks::class)->verdict(commentCandidateFor($this->item, 'First'));
    $second = app(CommentChecks::class)->verdict(commentCandidateFor($this->item, 'Second'));

    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and(Checker::$asked)->toBe(['First']);
});
