<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Domain\Fediverse\Jobs\ProcessInboxActivity;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use App\Domain\Fediverse\Remote\RemoteFetcher;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\ModerationVerdict;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;
use Tests\Fixtures\Plugins\Checker\Checker;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/checker/src/Checker.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('editor');
    Actors::blog();
    Inbox::switch(true);
    ContentActivities::listen(app(HookDispatcher::class));
    $this->alice = Actors::remote(Inbox::ALICE, ['profile_url' => 'https://remote.example/@alice']);
    $this->item = addrPublished($this->author, 'Harbour news');
    $this->post = FediverseObject::query()->where('content_id', $this->item->id)->value('uri');
    Checker::reset();
});

/**
 * Runs a Create of a Note answering the post from the account, through the inbox's job.
 */
function fcheckReply(string $inReplyTo, string $id, string $html): void
{
    $from = Inbox::ALICE;

    (new ProcessInboxActivity([
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $id.'/activity',
        'type' => 'Create',
        'actor' => $from,
        'object' => ['id' => $id, 'type' => 'Note', 'attributedTo' => $from, 'inReplyTo' => $inReplyTo, 'content' => $html],
    ], $from.'#main-key', $from))->handle(app(FediverseDocuments::class), app(RemoteFetcher::class));
}

/**
 * Enables the checker plugin as the operator and loads it.
 */
function fcheckEnabled(): void
{
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    PluginFixtures::use('checker');
    $manifest = app(PluginRegistry::class)->find('tests/checker')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/checker', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
}

it('holds a fediverse reply the plugin marks as spam, before the save', function (): void {
    fcheckEnabled();
    Checker::$verdict = fn (CommentCandidate $comment): ModerationVerdict => new ModerationVerdict(ModerationVerdict::SPAM, 'Spam.');

    fcheckReply($this->post, 'https://remote.example/statuses/9', '<p>Buy now.</p>');

    expect(Comment::query()->sole()->status)->toBe(ModerationVerdict::SPAM)
        ->and(Checker::$asked)->toBe(['Buy now.']);
});

it('leaves a fediverse reply to core’s moderator when no plugin fills the slot', function (): void {
    fcheckReply($this->post, 'https://remote.example/statuses/10', '<p>Lovely harbour.</p>');

    expect(Comment::query()->sole()->status)->toBe('pending');
});
