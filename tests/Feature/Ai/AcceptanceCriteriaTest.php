<?php

declare(strict_types=1);

use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Asking\Jobs\AnswerAiRequest;
use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Contents\Models\ContentRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * The spec's acceptance criteria 4 and 5, walked through the real routes and
 * Operations with the fake provider
 * (docs/superpowers/specs/2026-09-17-cms-design.md, section 7).
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    // "rise on", in the second paragraph, runs from 6 to 13.
    $this->item = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday.');
    $this->fake = rewriteReady();

    // Asks for the Shorter rewrite of "rise on", as the selection menu does; the JSON answer.
    $this->ask = fn () => aiAsk($this->author, $this->item, '1', ['from' => 6, 'to' => 13, 'variant' => 'shorter'], 'rewrite');

    // The two paragraphs of a revision, in order.
    $this->paragraphs = function (string $revisionId): array {
        $document = (array) ContentRevision::query()->findOrFail($revisionId)->document;

        return [TextBlocks::text(TextBlocks::at($document, '0') ?? []), TextBlocks::text(TextBlocks::at($document, '1') ?? [])];
    };

    // Publishes a revision as the editor's Publish does.
    $this->publish = fn (string $revisionId) => $this->actingAs($this->author)
        ->postJson(route('content.publish', $this->item->id), ['revision_id' => $revisionId]);
});

it('keeps every word and revision when an AI request fails, says why in one plain sentence, and lets the author publish (criterion 4)', function (AiFailure $failure, AiFailureType $type): void {
    $this->fake->fail($failure);
    $working = $this->item->working_revision_id;
    $revisions = ContentRevision::query()->where('content_id', $this->item->id)->count();

    $id = ($this->ask)()->assertStatus(202)->json('id');

    aiCollect($this->author, $id)->assertOk()->assertJson(['state' => 'failed', 'message' => $type->sentence()]);

    expect($this->item->refresh()->working_revision_id)->toBe($working)
        ->and(ContentRevision::query()->where('content_id', $this->item->id)->count())->toBe($revisions)
        ->and(($this->paragraphs)($working))->toBe(['The ferry is late.', 'Fares rise on Monday.'])
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe($type === AiFailureType::Refused ? AiOutcome::Refused : AiOutcome::Failed);

    ($this->publish)($working)->assertOk();

    expect($this->item->refresh()->published_revision_id)->toBe($working);
})->with([
    'the service can\'t be reached' => [fn (): AiFailure => AiFailure::unavailable(), AiFailureType::Unavailable],
    'the service is busy' => [fn (): AiFailure => AiFailure::rateLimited(), AiFailureType::RateLimited],
    'the service declines' => [fn (): AiFailure => AiFailure::refused(), AiFailureType::Refused],
    'the answer can\'t be used' => [fn (): AiFailure => AiFailure::invalidOutput(), AiFailureType::InvalidOutput],
    'the service takes too long' => [fn (): AiFailure => AiFailure::timedOut(), AiFailureType::Timeout],
]);

it('lets the author publish while a request is still working, and changes nothing live when it ends (criterion 4)', function (string $ending): void {
    Queue::fake();
    $working = $this->item->working_revision_id;
    $id = ($this->ask)()->assertStatus(202)->json('id');
    aiCollect($this->author, $id)->assertOk()->assertJson(['state' => 'working']);

    ($this->publish)($working)->assertOk();

    expect($this->item->refresh()->published_revision_id)->toBe($working);

    $ending === 'failing' ? $this->fake->fail(AiFailure::timedOut()) : $this->fake->answer(aiText('go up'));
    $job = Queue::pushed(AnswerAiRequest::class)->first();
    app()->call([$job, 'handle']);

    expect($this->item->refresh()->published_revision_id)->toBe($working)
        ->and($this->item->working_revision_id)->toBe($working)
        ->and(($this->paragraphs)($working))->toBe(['The ferry is late.', 'Fares rise on Monday.'])
        ->and(aiCollect($this->author, $id)->json('state'))->toBe($ending === 'failing' ? 'failed' : 'ready');
})->with(['failing', 'answering']);

it('proposes a rewrite of a live article, which the author compares, uses as a draft and takes back by restoring, the live text never moving (criterion 5)', function (): void {
    listsPublish($this->item, $this->author);
    $live = (string) $this->item->refresh()->published_revision_id;
    $this->fake->answer(aiText('go up'));

    // AI proposes: the selection's words, and what it offers in their place.
    $id = ($this->ask)()->assertStatus(202)->json('id');
    aiCollect($this->author, $id)->assertOk()->assertJson(['state' => 'ready', 'values' => ['text' => 'go up']]);

    // Use, as the editor sends it: the block as it would be, on the revision the editor's work is based on.
    $before = (string) $this->item->refresh()->working_revision_id;
    $assistant = $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id), [
        'revision_id' => $before,
        'path' => '1',
        'block' => docText('Fares go up Monday.'),
    ])->assertOk()->json('revisionId');

    expect(($this->paragraphs)($assistant))->toBe(['The ferry is late.', 'Fares go up Monday.'])
        ->and(ContentRevision::query()->findOrFail($assistant)->only(['kind', 'origin']))->toBe(['kind' => 'ai', 'origin' => 'ai'])
        ->and($this->item->refresh()->working_revision_id)->toBe($assistant)
        ->and($this->item->published_revision_id)->toBe($live);

    // Compare: the live text against the assistant's draft, the one paragraph changed.
    $this->actingAs($this->author)
        ->get(route('content.compare', ['content' => $this->item->id, 'to' => $assistant]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/compare')
            ->where('from.id', $live)
            ->where('to.id', $assistant)
            ->where('blocks', fn (Collection $blocks): bool => $blocks->where('kind', 'changed')->count() === 1
                && str_contains($blocks->firstWhere('kind', 'changed')['after'], '<ins>go</ins> <del>on</del><ins>up</ins>')));

    // Restore the revision before the assistant's: the old words come back as the working draft, and the assistant's stays in History.
    $this->actingAs($this->author)->post(route('content.revisions.restore', [$this->item->id, $before]))->assertRedirect();

    $restored = (string) $this->item->refresh()->working_revision_id;

    expect($restored)->not->toBe($assistant)
        ->and(($this->paragraphs)($restored))->toBe(['The ferry is late.', 'Fares rise on Monday.'])
        ->and(ContentRevision::query()->whereKey($assistant)->exists())->toBeTrue()
        ->and($this->item->published_revision_id)->toBe($live)
        ->and(($this->paragraphs)($live))->toBe(['The ferry is late.', 'Fares rise on Monday.'])
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Applied);
});
