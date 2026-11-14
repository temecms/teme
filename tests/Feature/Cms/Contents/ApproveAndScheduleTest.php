<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\ApproveReview;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->contributor = User::factory()->create()->assignRole('contributor');
    $this->author = User::factory()->create()->assignRole('author');
});

it('approves the submitted version and schedules it, without publishing now', function (): void {
    $this->freezeTime();
    $content = lifecycleDraft($this->contributor, 'Harbour festival');
    $reviewId = schedSubmit($content->refresh(), $this->contributor);
    $revisionId = DB::table('content_reviews')->where('id', $reviewId)->value('revision_id');
    $runAt = now()->addDay()->toIso8601String();

    app(ApproveReview::class)->execute(new ReviewDecisionInput($reviewId, (string) $this->editor->id, null, $runAt));

    expect($content->refresh()->status)->not->toBe('published')
        ->and(DB::table('content_reviews')->where('id', $reviewId)->value('decision'))->toBe('approved')
        ->and((array) schedRow($content))->toMatchArray(['revision_id' => $revisionId, 'created_by' => $this->editor->id]);
});

it('still publishes at once when no time is given', function (): void {
    $content = lifecycleDraft($this->contributor, 'Harbour festival');
    $reviewId = schedSubmit($content->refresh(), $this->contributor);

    app(ApproveReview::class)->execute(new ReviewDecisionInput($reviewId, (string) $this->editor->id));

    expect($content->refresh()->status)->toBe('published');
});

it('refuses a time in the past', function (): void {
    $content = lifecycleDraft($this->contributor, 'Harbour festival');
    $reviewId = schedSubmit($content->refresh(), $this->contributor);

    expect(fn () => app(ApproveReview::class)->execute(new ReviewDecisionInput($reviewId, (string) $this->editor->id, null, now()->subHour()->toIso8601String())))
        ->toThrow(ValidationException::class);
});

it('writes nothing when someone who may not publish the item tries it', function (): void {
    $content = lifecycleDraft($this->contributor, 'Harbour festival');
    $reviewId = schedSubmit($content->refresh(), $this->contributor);

    expect(fn () => app(ApproveReview::class)->execute(new ReviewDecisionInput($reviewId, (string) $this->author->id, null, now()->addDay()->toIso8601String())))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_reviews')->where('id', $reviewId)->value('decided_at'))->toBeNull()
        ->and(DB::table('scheduled_publications')->where('content_id', $content->id)->count())->toBe(0);
});

it('approves and schedules through the editor, and says when', function (): void {
    $content = lifecycleDraft($this->contributor, 'Harbour festival');
    $reviewId = schedSubmit($content->refresh(), $this->contributor);

    $this->actingAs($this->editor)
        ->postJson(route('content.review.approve', ['content' => $content->id]), [
            'review_id' => $reviewId,
            'run_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('status', 'scheduled')
        ->assertJsonPath('review', null)
        ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Approved and scheduled for '));
});
