<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Domain\Site\ViewModels\CommentFormView;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [
        base_path('tests/Fixtures/themes/overrides-form'),
        base_path('tests/Fixtures/themes/overrides-form-bare'),
        base_path('tests/Fixtures/themes/good'),
    ]]);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = cmItem($this->author);
});

function commentFormView(): CommentFormView
{
    return new CommentFormView(
        action: '/_teme/comments',
        path: '/harbour-news/',
        parentId: null,
        parentName: null,
        askWebsite: false,
        signedInOnly: false,
        keepIpDays: 30,
    );
}

it('draws a comment-form override that includes core\'s fields, which carry the honeypot and the tokens', function (): void {
    themeActivate('overrides-form');

    $html = Blade::render('<x-teme::comment-form :form="$form" />', ['form' => commentFormView()]);

    expect($html)->toContain('data-override="comment-form"')
        ->toContain('name="path"')
        ->toContain('name="parent"')
        ->toContain('name="token"')
        ->toContain('name="_token"')
        ->toContain('name="subject"');
});

it('accepts a comment posted through a comment-form override that includes core\'s fields', function (): void {
    themeActivate('overrides-form');

    cmPost($this->item, ['text' => 'Lovely piece.'])->assertOk();

    expect(Comment::query()->count())->toBe(1);
});

it('refuses a comment-form override that doesn\'t include core\'s fields or post to the form\'s action', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-form-bare'])
        ->expectsOutputToContain('comment-form-fields')
        ->expectsOutputToContain('$form->action')
        ->assertFailed();
});
