<?php

declare(strict_types=1);

use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Tasks\AltText\Operations\Input\RecordAltTextChangeInput;
use App\Domain\Ai\Tasks\AltText\Operations\RecordAltTextChange;
use App\Domain\Media\Operations\DeleteMediaPermanently;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Ola Author'])->assignRole('author');
    $this->media = altImage($this->author);
    $this->fake = altReady();
    // Asks for the image's alt text as the author and uses the answer; the request's id.
    $this->use = function (string $answer): string {
        $this->fake->answer(altAnswer($answer));
        $id = altAsk($this->author, $this->media)->json('id');
        $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id))->assertOk();

        return $id;
    };
    $this->changes = fn (): array => DB::table('ai_alt_text_changes')->get(['activity_id', 'media_id', 'alt_before', 'alt_after'])->keyBy('activity_id')->map(fn (object $row): array => (array) $row)->all();
});

it('keeps the alt text from before and after each suggestion used, and nothing for one put aside or out of date (I-1312)', function (): void {
    $first = ($this->use)('Fishing boats at dawn.');
    $second = ($this->use)('Fishing boats moored in Bonny harbour at dawn.');

    $this->fake->answer(altAnswer('Put aside.'));
    $discarded = altAsk($this->author, $this->media)->json('id');
    $this->actingAs($this->author)->deleteJson(route('ai.requests.destroy', $discarded))->assertNoContent();

    $this->fake->answer(altAnswer('Out of date.'));
    $stale = altAsk($this->author, $this->media)->json('id');
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($this->media->id, (string) $this->author->id, 'Holiday', 'Typed by hand', null, null));
    $this->actingAs($this->author)->postJson(route('ai.requests.apply', $stale))->assertStatus(409);

    // Keyed by request, so the order rows come back in doesn't matter.
    expect(($this->changes)())->toEqual([
        $first => ['activity_id' => $first, 'media_id' => $this->media->id, 'alt_before' => null, 'alt_after' => 'Fishing boats at dawn.'],
        $second => ['activity_id' => $second, 'media_id' => $this->media->id, 'alt_before' => 'Fishing boats at dawn.', 'alt_after' => 'Fishing boats moored in Bonny harbour at dawn.'],
    ]);
});

it('keeps a change only for the person who asked, about the image asked about, and writes nothing otherwise', function (string $case): void {
    $this->fake->answer(altAnswer('Fishing boats at dawn.'));
    $id = altAsk($this->author, $this->media)->json('id');
    $other = altImage($this->author, 1000, 700);

    [$activity, $media, $actor] = match ($case) {
        'someone else' => [$id, $this->media->id, (string) $this->admin->id],
        'another image' => [$id, $other->id, (string) $this->author->id],
        'a request about something else' => [actvRow($this->author), $this->media->id, (string) $this->author->id],
    };

    expect(fn () => app(RecordAltTextChange::class)->execute(new RecordAltTextChangeInput($activity, $media, null, 'Fishing boats at dawn.', $actor)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('ai_alt_text_changes')->count())->toBe(0);
})->with(['someone else', 'another image', 'a request about something else']);

it('refuses a change without new alt text, or longer than the field holds', function (string $after): void {
    $this->fake->answer(altAnswer('Fishing boats at dawn.'));
    $id = altAsk($this->author, $this->media)->json('id');

    expect(fn () => app(RecordAltTextChange::class)->execute(new RecordAltTextChangeInput($id, $this->media->id, null, $after, (string) $this->author->id)))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('ai_alt_text_changes')->count())->toBe(0);
})->with(['empty' => ['   '], 'too long' => [str_repeat('a', 1001)]]);

it('keeps one change a request: none for a request already used', function (): void {
    $id = ($this->use)('Fishing boats at dawn.');

    expect(fn () => app(RecordAltTextChange::class)->execute(new RecordAltTextChangeInput($id, $this->media->id, 'Fishing boats at dawn.', 'Boats.', (string) $this->author->id)))
        ->toThrow(ActivityAlreadySettled::class)
        ->and(DB::table('ai_alt_text_changes')->count())->toBe(1);
});

it('forgets a change with its request after 12 months, and with the image when it is deleted for good', function (): void {
    ($this->use)('Fishing boats at dawn.');

    $this->travelTo(now()->addMonths(13));
    $this->artisan('teme:ai-activity:prune')->assertSuccessful();

    expect(DB::table('ai_alt_text_changes')->count())->toBe(0);

    // A session a year old has expired: the second half runs in the present.
    $this->travelBack();

    $later = ($this->use)('Fishing boats moored at dawn.');
    app(TrashMedia::class)->execute(new MediaActionInput($this->media->id, (string) $this->author->id));
    app(DeleteMediaPermanently::class)->execute(new MediaActionInput($this->media->id, (string) $this->author->id));

    expect(DB::table('ai_alt_text_changes')->count())->toBe(0)
        ->and(AiActivity::query()->whereKey($later)->exists())->toBeTrue();
});

it('shows an administrator the alt text before and after on a used request, and on no other (I-1312)', function (): void {
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($this->media->id, (string) $this->author->id, 'Holiday', 'Boats', null, null));
    $used = ($this->use)('Fishing boats at dawn.');
    $other = actvRow($this->author);

    $this->actingAs($this->admin)->get(route('tools.ai-activity.index', ['row' => $used]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('details.id', $used)
            ->where('details.altText', ['before' => 'Boats', 'after' => 'Fishing boats at dawn.'])
            ->missing('rows.0.altText'));

    $this->actingAs($this->admin)->get(route('tools.ai-activity.index', ['row' => $other]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('details.id', $other)->where('details.altText', null));
});
