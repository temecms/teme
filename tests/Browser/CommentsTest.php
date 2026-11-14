<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->site = dtSite($this->author);
});

it('passes axe on the answer to a comment posted without JavaScript, and on the form sent back with its errors (I-593)', function (string $scheme): void {
    designPublish(['theme.default' => ['color_scheme' => $scheme]]);

    visit(dtUrl($this->site, 'longRead'), ['javaScriptEnabled' => false])
        ->assertScript("document.querySelector('[data-teme-remember]').hidden")
        ->type('#c-text', 'A reader writes from the quay.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->pressAndWaitFor('Post comment')
        ->assertSee('Your comment is awaiting moderation');

    visit(dtUrl($this->site, 'longRead'), ['javaScriptEnabled' => false])
        ->pressAndWaitFor('Post comment')
        ->assertSee('There’s a problem');

    // Axe needs page scripts, which the plugin injects and javaScriptEnabled: false turns off (B-360): the same
    // answer pages, reached from the reply page, a page of its own whose form posts the ordinary way (1.8b′'s D9).
    visit(dtUrl($this->site, 'replyPage'))
        ->type('#c-text', 'A reader answers from the quay.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->pressAndWaitFor('Post reply')
        ->assertSee('Your comment is awaiting moderation')
        ->assertNoAccessibilityIssues(3);

    visit(dtUrl($this->site, 'replyPage'))
        ->pressAndWaitFor('Post reply')
        ->assertSee('There’s a problem')
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);
