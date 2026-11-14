<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->item = cmItem(User::factory()->create()->assignRole('author'));
});

it('refuses what a guest gives in the form\'s own words (D14)', function (array $overrides, string $field, string $message): void {
    expect(fn () => cmSubmit($this->item, overrides: $overrides))->toThrow(function (ValidationException $refused) use ($field, $message): void {
        expect($refused->errors()[$field][0] ?? null)->toBe($message);
    });
})->with([
    'no words' => [['text' => "  \n "], 'text', 'Write your comment.'],
    'too many words' => [['text' => str_repeat('a', 5234)], 'text', 'Shorten your comment to 5,000 characters or fewer. It has 5,234.'],
    'no name' => [['name' => ''], 'name', 'Enter your name.'],
    'a name too long' => [['name' => str_repeat('a', 101)], 'name', 'Enter a name of 100 characters or fewer.'],
    'no email address' => [['email' => null], 'email', 'Enter your email address.'],
    'an email address that isn\'t one' => [['email' => 'ada at example'], 'email', 'Enter an email address like name@example.com.'],
    'a website that isn\'t on the web' => [['url' => 'javascript:alert(1)'], 'url', 'Enter a web address that starts with https://, or leave this empty.'],
]);

it('takes a website typed without its scheme as https, and a port as no scheme', function (): void {
    $first = cmSubmit($this->item, 'One.', ['url' => ' ada.example/about ']);
    $second = cmSubmit($this->item, 'Two.', ['url' => 'localhost:8080']);

    expect(Comment::query()->findOrFail($first->commentId)->author_url)->toBe('https://ada.example/about')
        ->and(Comment::query()->findOrFail($second->commentId)->author_url)->toBe('https://localhost:8080');
});

it('keeps no website while the site doesn\'t ask guests for one, whatever a form sends (I-596)', function (): void {
    cmSettings(['ask_website' => false]);

    $submitted = cmSubmit($this->item, overrides: ['url' => 'javascript:alert(1)']);

    expect(Comment::query()->findOrFail($submitted->commentId)->author_url)->toBeNull();
});
