<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
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
        base_path('tests/Fixtures/themes/overrides-form-noattr'),
        base_path('tests/Fixtures/themes/good'),
    ]]);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = cmItem($this->author);
});

function commentFormView(string $path = '/harbour-news/'): CommentFormView
{
    return new CommentFormView(
        action: '/_teme/comments',
        path: $path,
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

it('accepts the comment an override’s own form posts, with the fields it drew', function (): void {
    themeActivate('overrides-form');

    $html = Blade::render('<x-teme::comment-form :form="$form" />', ['form' => commentFormView((string) Addresses::relativeUrl($this->item))]);
    $page = new DOMDocument;
    @$page->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $fields = [];

    foreach ((new DOMXPath($page))->query('//form//input[@name]|//form//textarea[@name]') ?: [] as $field) {
        $fields[$field->getAttribute('name')] = $field->nodeName === 'textarea' ? '' : $field->getAttribute('value');
    }

    expect($fields)->toHaveKeys(['path', 'parent', 'token', 'subject', 'text']);

    cmPost($this->item, [...$fields, 'text' => 'Lovely piece.'])->assertOk();

    expect(Comment::query()->count())->toBe(1);
});

it('refuses a comment-form override that doesn\'t include core\'s fields or post to the form\'s action', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-form-bare'])
        ->expectsOutputToContain('comment-form-fields')
        ->expectsOutputToContain('$form->action')
        ->assertFailed();
});

it('refuses a comment-form override whose form lacks data-teme-form, and nothing else', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-form-noattr'])
        ->expectsOutputToContain('data-teme-form')
        ->doesntExpectOutputToContain('comment-form-fields')
        ->doesntExpectOutputToContain('$form->action')
        ->assertFailed();
});
