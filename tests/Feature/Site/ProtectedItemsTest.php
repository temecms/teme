<?php

declare(strict_types=1);

use App\Domain\Redirects\Queries\SiteAnswers;
use App\Domain\Site\ReaderKeys;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->item = Visibilities::change(Visibilities::published($this->editor, 'Members update', 'zqprotected'), 'protected', $this->editor, 'Harbour 2024');
});

it('answers a protected item\'s address with core\'s form: its title, never its words, excerpt or description (I-1700)', function (): void {
    $response = cacheGet('/members-update/')->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Members update')
        ->toContain('This post is protected by a password. Enter it to read on.')
        ->toContain('action="/_teme/unlock"')
        ->toContain('<input type="hidden" name="path" value="/members-update/">')
        ->toContain('autocomplete="current-password"')
        ->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('zqprotectedbody')
        ->not->toContain('zqprotectedexcerpt')
        ->not->toContain('zqprotecteddescription')
        ->not->toContain('og:description')
        ->not->toContain('BlogPosting')
        // The theme's floor, which FloorTest's fixed list doesn't reach: one main, one heading.
        ->and(substr_count($html, '<main'))->toBe(1)
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('opens the page to a reader whose cookie holds its key, among others, and to no one else (I-1700)', function (): void {
    $key = Visibilities::cookie($this->item);
    $other = Visibilities::change(Visibilities::published($this->editor, 'Board minutes', 'zqboard'), 'protected', $this->editor, 'Tide 2025');

    expect((string) cacheGet('/members-update/', [ReaderKeys::COOKIE => $key])->getContent())->toContain('zqprotectedbody')
        ->and((string) cacheGet('/members-update/', [ReaderKeys::COOKIE => Visibilities::cookie($other).'.'.$key])->getContent())->toContain('zqprotectedbody')
        ->and((string) cacheGet('/members-update/', [ReaderKeys::COOKIE => Visibilities::cookie($other)])->getContent())->not->toContain('zqprotectedbody')
        ->and((string) cacheGet('/members-update/', [ReaderKeys::COOKIE => 'not a key'])->getContent())->not->toContain('zqprotectedbody')
        ->and((string) cacheGet('/members-update/', [ReaderKeys::COOKIE => str_repeat('A', 22)])->getContent())->not->toContain('zqprotectedbody');
});

it('asks again once the password changes, whatever the cookie holds (I-1700)', function (): void {
    $key = Visibilities::cookie($this->item);
    Visibilities::change($this->item, 'protected', $this->editor, 'Harbour 2025');

    expect((string) cacheGet('/members-update/', [ReaderKeys::COOKIE => $key])->getContent())
        ->not->toContain('zqprotectedbody')
        ->toContain('This post is protected by a password.');
});

it('marks every answer at a protected item\'s address no-store and private, the form and the page alike (I-1700)', function (): void {
    Visibilities::published($this->editor, 'Harbour news public', 'zqpublic');
    $locked = cacheGet('/members-update/');
    $open = cacheGet('/members-update/', [ReaderKeys::COOKIE => Visibilities::cookie($this->item)]);

    expect($locked->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($open->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and(cacheGet('/harbour-news-public/')->headers->get('Cache-Control'))->not->toBe('no-store, private');
});

it('locks a protected item\'s later comment pages too, and shows its thread, without a form, once open (I-1700)', function (): void {
    cmSettings(['per_page' => 1]);
    cmComment($this->item, attributes: ['author_name' => 'Zqfirst Reader']);
    cmComment($this->item, attributes: ['author_name' => 'Zqsecond Reader']);
    $key = [ReaderKeys::COOKIE => Visibilities::cookie($this->item)];

    $locked = (string) cacheGet('/members-update/comments/page/2/')->getContent();
    $open = (string) cacheGet('/members-update/', $key)->getContent();

    expect($locked)->toContain('action="/_teme/unlock"')
        ->toContain('<input type="hidden" name="path" value="/members-update/comments/page/2/">')
        ->not->toContain('Zqsecond Reader')
        ->and($open)->toContain('Zqfirst Reader')
        // Not the form's data attribute, which the thread's script names whether or not a form is drawn.
        ->and($open)->not->toContain('class="teme-comment-form"')
        ->and((string) cacheGet('/members-update/comments/page/2/', $key)->getContent())->toContain('Zqsecond Reader');
});

it('tells the importer\'s check that a protected item\'s address answers (I-1700)', function (): void {
    expect(app(SiteAnswers::class)->at('/members-update/')->status)->toBe(200);
});
