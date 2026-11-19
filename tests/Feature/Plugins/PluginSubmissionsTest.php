<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\ReceiveSubmissionInput;
use App\Domain\Contents\Operations\ReceiveSubmission;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginSubmissions;
use App\Domain\Plugins\Models\PluginRecord;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Teme\Contracts\Content\Submission;
use Teme\Contracts\Content\Submissions;
use Teme\Contracts\Content\WriteRefused;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->enable = fn (array $capabilities = ['content.submissions'], string $state = PluginRecord::ENABLED): PluginRecord => PluginRecord::factory()->enabled()->create([
        'name' => 'tests/forms',
        'code' => 'forms',
        'version' => '1.0.0',
        'state' => $state,
        'consent' => ['capabilities' => $capabilities, 'hosts' => [], 'hooks' => []],
    ]);

    $this->door = fn (): Submissions => app(PluginDoors::class)->for('tests/forms', 'forms', ['content.submissions'], [])[Submissions::class];

    $this->from = function (string $address): void {
        app()->instance('request', Request::create('/contact', 'POST', server: ['REMOTE_ADDR' => $address]));
    };

    $this->sent = fn (string $title = 'A question about opening hours'): Submission => new Submission(
        type: 'post',
        title: $title,
        body: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Are you open on Sunday?']]]]],
        from: 'Ada, ada@example.org',
    );

    $this->counts = fn (): array => [
        'contents' => Content::query()->count(),
        'revisions' => ContentRevision::query()->count(),
        'reviews' => ContentReview::query()->count(),
    ];

    RateLimiter::clear('plugin-submissions:forms');
    ($this->from)('203.0.113.5');
});

it('offers Submissions only when content.submissions is granted', function (): void {
    $doors = app(PluginDoors::class);

    expect($doors->for('tests/forms', 'forms', ['content.submissions'], [])[Submissions::class])->toBeInstanceOf(PluginSubmissions::class)
        ->and($doors->for('tests/forms', 'forms', ['content.write'], []))->not->toHaveKey(Submissions::class);
});

it('keeps what a visitor sends as an item waiting for review, written as the site, marked as a submission, and never published', function (): void {
    ($this->enable)();

    $item = ($this->door)()->submit(($this->sent)());
    $content = Content::query()->findOrFail($item->id);
    $revision = ContentRevision::query()->findOrFail($content->working_revision_id);
    $review = ContentReview::query()->where('content_id', $item->id)->sole();

    expect($item->status)->toBe('review')
        ->and($item->submission)->toBeTrue()
        ->and($item->url)->toBeNull()
        ->and($content->author_id)->toBeNull()
        ->and($content->submitted_via)->toBe('forms')
        ->and($content->submitted_from)->toBe('Ada, ada@example.org')
        ->and($content->published_revision_id)->toBeNull()
        ->and($revision->author_id)->toBeNull()
        ->and($revision->via)->toBe('forms')
        ->and($revision->title)->toBe('A question about opening hours')
        ->and($review->submitted_by)->toBeNull()
        ->and($review->decided_at)->toBeNull()
        ->and($review->revision_id)->toBe($content->working_revision_id);
});

it('writes nothing for a plugin that isn’t enabled, or wasn’t granted content.submissions', function (array $capabilities, string $state): void {
    ($this->enable)($capabilities, $state);
    $before = ($this->counts)();

    expect(fn () => app(ReceiveSubmission::class)->execute(new ReceiveSubmissionInput('forms', 'post', 'Hello', ['type' => 'doc', 'content' => []])))
        ->toThrow(AuthorizationException::class)
        ->and(($this->counts)())->toBe($before);
    expect(fn () => ($this->door)()->submit(($this->sent)()))->toThrow(WriteRefused::class)
        ->and(($this->counts)())->toBe($before);
})->with([
    'not granted' => [['content.write'], PluginRecord::ENABLED],
    'disabled' => [['content.submissions'], PluginRecord::DISABLED],
]);

it('refuses a term the site doesn’t have, and creates none', function (): void {
    ($this->enable)();
    $before = ($this->counts)();

    expect(fn () => ($this->door)()->submit(new Submission('post', 'Tagged', ['type' => 'doc', 'content' => []], terms: ['category' => ['Made up']])))
        ->toThrow(WriteRefused::class, 'There is no Made up in category.')
        ->and(($this->counts)())->toBe($before);
});

it('takes ten submissions an hour from one visitor address, then refuses and writes nothing', function (): void {
    ($this->enable)();
    $door = ($this->door)();

    foreach (range(1, PluginSubmissions::MOST_PER_VISITOR) as $n) {
        $door->submit(($this->sent)("Question {$n}"));
    }

    $before = ($this->counts)();

    expect(fn () => $door->submit(($this->sent)()))->toThrow(WriteRefused::class, 'Too many submissions from this visitor. Try again in an hour.')
        ->and(($this->counts)())->toBe($before);

    ($this->from)('198.51.100.7');

    expect($door->submit(($this->sent)('From someone else'))->status)->toBe('review');
});

it('takes a limited number of submissions an hour through one plugin, whoever sends them', function (): void {
    ($this->enable)();
    $door = ($this->door)();

    foreach (range(1, PluginSubmissions::MOST_PER_PLUGIN) as $n) {
        RateLimiter::hit('plugin-submissions:forms', 3600);
    }

    expect(fn () => $door->submit(($this->sent)()))->toThrow(WriteRefused::class, 'This form has taken too many submissions this hour. Try again later.');
});
