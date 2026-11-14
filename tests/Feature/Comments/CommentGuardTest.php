<?php

declare(strict_types=1);

use App\Domain\Cache\SignedInMarker;
use App\Domain\Comments\Models\Comment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->item = cmItem($this->editor);
});

it('refuses a form another site sent, by its Fetch Metadata or its Origin, keeping the words and writing nothing (D7)', function (array $headers): void {
    cmPost($this->item, ['text' => 'Forged words'], headers: $headers)->assertForbidden()
        ->assertSee('This form came from another site', false)->assertSee('Forged words', false);

    expect(Comment::query()->count())->toBe(0);
})->with([
    'cross-site' => [['Sec-Fetch-Site' => 'cross-site']],
    'same-site' => [['Sec-Fetch-Site' => 'same-site']],
    'typed in the address bar' => [['Sec-Fetch-Site' => 'none']],
    'another site\'s Origin' => [['Origin' => 'https://elsewhere.example']],
    'a null Origin' => [['Origin' => 'null']],
]);

it('takes a guest\'s comment with no session read, no cookie set and no token, from this site\'s own page (D7)', function (array $headers): void {
    $response = cmPost($this->item, headers: $headers)->assertOk();

    expect(Comment::query()->count())->toBe(1)
        ->and($response->headers->getCookies())->toBe([]);
})->with([
    'saying so' => [['Sec-Fetch-Site' => 'same-origin']],
    'by its Origin' => [['Origin' => 'http://localhost']],
    'saying nothing' => [[]],
]);

it('takes a signed-in editor\'s comment as their own, approved at once, when the form is this site\'s (D7, I-483)', function (): void {
    $this->actingAs($this->editor);

    cmPost($this->item, ['name' => 'Someone Else'], cookies: [SignedInMarker::COOKIE => '1'])->assertOk()
        ->assertSee('Your comment is posted', false);

    expect(Comment::query()->sole()->only(['author_name', 'author_user_id', 'status']))
        ->toBe(['author_name' => 'Eve Editor', 'author_user_id' => $this->editor->id, 'status' => 'approved']);
});

it('refuses a signed-in browser\'s form that says nothing of where it came from, unless it carries the session\'s token (D7)', function (): void {
    $this->actingAs($this->editor);
    $this->withSession(['_token' => 'session-token-0123456789']);

    cmPost($this->item, headers: [], cookies: [SignedInMarker::COOKIE => '1'])->assertForbidden()
        ->assertSee('Turn on JavaScript', false);

    expect(Comment::query()->count())->toBe(0);

    cmPost($this->item, ['_token' => 'session-token-0123456789'], headers: [], cookies: [SignedInMarker::COOKIE => '1'])->assertOk();

    expect(Comment::query()->count())->toBe(1);
});
