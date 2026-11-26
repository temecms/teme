<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Site\ListingViews;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->withElevation();
    $this->author = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane.private@example.com'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    $this->save = fn (?array $profile, ?User $actor = null) => app(UpdateProfile::class)->execute(new UpdateProfileInput(
        userId: (string) $this->author->id,
        name: 'Jane Doe',
        email: $this->author->email,
        actorId: (string) ($actor ?? $this->author)->id,
        publicProfile: $profile,
    ));
    $this->subject = fn () => app(ListingViews::class)->forAuthor($this->author->refresh(), 1)->subject;
});

it('shows the role, the coverage and the links an author filled, and the exact count of what they published', function (): void {
    ltTypes();
    ltItem($this->author, 'post', 'One');
    ltItem($this->author, 'post', 'Two');

    ($this->save)(['role' => ' Senior editor ', 'coverage' => 'Harbours and ferries', 'links' => [['label' => 'Mastodon', 'url' => 'https://example.social/@jane'], ['label' => '', 'url' => '']]]);

    $subject = ($this->subject)();

    expect($subject->role)->toBe('Senior editor')
        ->and($subject->coverage)->toBe('Harbours and ferries')
        ->and($subject->links)->toHaveCount(1)
        ->and($subject->links[0]->label)->toBe('Mastodon')
        ->and($subject->links[0]->url)->toBe('https://example.social/@jane')
        ->and($subject->publishedCount)->toBe(2);

    $html = (string) addrGet('/author/'.$this->author->slug.'/')->assertOk()->getContent();

    expect($html)->not->toContain('jane.private@example.com');
});

it('shows nothing of what is empty, and clears what an empty form sends', function (): void {
    expect(($this->subject)()->role)->toBeNull();

    ($this->save)(['role' => 'Editor']);
    expect(($this->subject)()->role)->toBe('Editor');

    ($this->save)(null);
    expect(($this->subject)()->role)->toBe('Editor');

    ($this->save)(['role' => '', 'coverage' => '', 'links' => []]);
    $subject = ($this->subject)();

    expect($subject->role)->toBeNull()
        ->and($subject->coverage)->toBeNull()
        ->and($subject->links)->toBe([])
        ->and(DB::table('users')->where('id', $this->author->id)->value('public_profile'))->toBeNull();
});

it('keeps a value a rule would refuse out of a template even if one was stored', function (): void {
    DB::table('users')->where('id', $this->author->id)->update(['public_profile' => json_encode(['links' => [['label' => 'Bad', 'url' => 'javascript:alert(1)'], ['label' => 'Good', 'url' => 'https://example.org/x']]])]);

    expect(array_map(fn ($link) => $link->label, ($this->subject)()->links))->toBe(['Good']);
});

it('refuses too many links, an address that is not https or http, and a long role, and writes nothing', function (array $profile, string $field): void {
    ($this->save)(['role' => 'Kept']);

    try {
        ($this->save)($profile);
        $this->fail('Expected a refusal.');
    } catch (ValidationException $invalid) {
        expect($invalid->errors())->toHaveKey($field);
    }

    expect(($this->subject)()->role)->toBe('Kept');
})->with([
    'seven links' => [['links' => array_map(fn (int $n): array => ['label' => "L{$n}", 'url' => "https://example.org/{$n}"], range(1, 7))], 'public_profile.links'],
    'a script address' => [['links' => [['label' => 'X', 'url' => 'javascript:alert(1)']]], 'public_profile.links.0.url'],
    'a link with no label' => [['links' => [['label' => '', 'url' => 'https://example.org']]], 'public_profile.links.0.label'],
    'a long role' => [['role' => str_repeat('r', 121)], 'public_profile.role'],
]);

it('writes nothing when authorization fails to change another person’s public profile', function (): void {
    $other = User::factory()->create()->assignRole('author');

    expect(fn () => ($this->save)(['role' => 'Hijacked'], $other))->toThrow(AuthorizationException::class)
        ->and(($this->subject)()->role)->toBeNull();
});

it('leaves a person with no account with none of it', function (): void {
    $page = app(ListingViews::class)->forAuthor((object) ['kind' => 'person', 'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'name' => 'Guest', 'slug' => 'guest', 'bio' => null, 'path' => null], 1);

    expect($page->subject?->role)->toBeNull()->and($page->subject?->links)->toBe([]);
});
