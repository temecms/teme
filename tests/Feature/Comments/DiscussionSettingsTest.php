<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Settings\Modules;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('switches comments on by default, in the modules group, and purges every page when switched (I-077, I-484, I-574)', function (): void {
    $group = app(SettingsRegistry::class)->find('modules');

    expect($group?->setting('comments')?->options)->toBe(['on' => 'On', 'off' => 'Off'])
        ->and($group?->setting('comments')?->default)->toBe('on')
        ->and($group?->ability)->toBe('settings.modules.manage')
        ->and($group?->cacheTags)->toBe(['*'])
        ->and(Modules::on(Modules::COMMENTS))->toBeTrue();

    cmSettings(['comments' => 'off'], 'modules');

    expect(Modules::on(Modules::COMMENTS))->toBeFalse();
});

it('declares the Discussion settings, with study S\'s defaults and v9\'s ranges (§3.10, E2)', function (): void {
    $group = app(SettingsRegistry::class)->find('discussion');
    $shape = [];

    foreach ($group?->settings ?? [] as $setting) {
        $shape[$setting->key] = [$setting->type->value, $setting->default, $setting->min, $setting->max];
    }

    expect($shape)->toBe([
        'open_for' => ['multiselect', ['post'], null, null],
        'signed_in_only' => ['boolean', false, null, null],
        'ask_website' => ['boolean', true, null, null],
        'close_after_days' => ['number', 0, 0, 3650],
        'thread_depth' => ['number', 5, 1, 10],
        'per_page' => ['number', 50, 1, 200],
        'hold_all' => ['boolean', false, null, null],
        'approve_returning' => ['boolean', true, null, null],
        'hold_links' => ['number', 2, 0, 100],
        'hold_list' => ['textarea', '', null, null],
        'block_list' => ['textarea', '', null, null],
        'email_moderators' => ['boolean', true, null, null],
        'email_authors' => ['boolean', true, null, null],
        'keep_ip_days' => ['number', 60, 1, 365],
    ])->and($group?->ability)->toBe('settings.discussion.manage')
        ->and($group?->cacheTags)->toBe(['discussion']);
});

it('offers each type that takes comments as a choice for new items open to them', function (): void {
    expect(app(SettingsRegistry::class)->find('discussion')?->setting('open_for')?->options)->toBe(['post' => 'Posts', 'page' => 'Pages']);
});

it('reads the lists a line at a time, trimmed, with blank lines dropped', function (): void {
    cmSettings(['hold_list' => "  casino \n\n\r\nloans", 'block_list' => "bet9ja-promo.example\n", 'thread_depth' => 3]);

    expect(DiscussionSettings::current()->holdList)->toBe(['casino', 'loans'])
        ->and(DiscussionSettings::current()->blockList)->toBe(['bet9ja-promo.example'])
        ->and(DiscussionSettings::current()->threadDepth)->toBe(3);
});

it('reads how many conversations a page holds, and whether guests are asked for a website (I-596)', function (): void {
    expect(DiscussionSettings::current())->perPage->toBe(50)->askWebsite->toBeTrue();

    cmSettings(['per_page' => 5, 'ask_website' => false]);

    expect(DiscussionSettings::current())->perPage->toBe(5)->askWebsite->toBeFalse();
});

it('starts new items as their type and the settings say: posts open, pages closed, until the settings change it (I-274)', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $editor = User::factory()->create()->assignRole('editor');

    expect(lifecycleDraft($author, 'Harbour news')->refresh()->comment_status)->toBe(Discussion::Open)
        ->and(lifecycleDraft($editor, 'About', 'page')->refresh()->comment_status)->toBe(Discussion::Closed);

    cmSettings(['open_for' => ['page']]);

    expect(lifecycleDraft($author, 'Tide tables')->refresh()->comment_status)->toBe(Discussion::Closed)
        ->and(lifecycleDraft($editor, 'Contact', 'page')->refresh()->comment_status)->toBe(Discussion::Open);
});

it('starts a new item of a type that takes no comments closed, whatever the settings name', function (): void {
    cmSettings(['open_for' => ['post', 'page', 'region']]);

    expect(DiscussionSettings::current()->forNewItem('region'))->toBe(Discussion::Closed)
        ->and(DiscussionSettings::current()->forNewItem('page'))->toBe(Discussion::Open);
});

it('reads the email settings and how long addresses are kept: on, on and 60 days unless changed (E2, I-270, I-271)', function (): void {
    expect(DiscussionSettings::current())->emailModerators->toBeTrue()->emailAuthors->toBeTrue()->keepIpDays->toBe(60);

    cmSettings(['email_moderators' => false, 'email_authors' => false, 'keep_ip_days' => 7]);

    expect(DiscussionSettings::current())->emailModerators->toBeFalse()->emailAuthors->toBeFalse()->keepIpDays->toBe(7);
});

it('words the page, the switch and the three new settings as v9 draws them (E1, E2)', function (): void {
    $discussion = app(SettingsRegistry::class)->find('discussion');
    $words = fn (string $key): array => [$discussion?->setting($key)?->label, $discussion?->setting($key)?->description];

    expect($discussion?->description)->toBe('Whether the site has comments, who can comment, which comments wait for approval, and the emails they send.')
        ->and(app(SettingsRegistry::class)->find('modules')?->setting('comments')?->description)
        ->toBe('Off, the site takes no comments, and visitors see no comments, form or counts. Comments leaves the menu and the editor. Stored comments and these settings are kept.')
        ->and($words('email_moderators'))->toBe(['Email moderators when a comment waits for approval', 'Sent to editors and administrators, and to the author of the post or page if they can publish it.'])
        ->and($words('email_authors'))->toBe(['Email the author when a comment on their post or page is approved', 'Each person can turn either email off for themselves in Account › Notifications.'])
        ->and($words('keep_ip_days'))->toBe(['Keep commenters’ IP addresses for', 'Each comment’s IP address and browser are erased after this many days, imported comments’ too. Only editors and administrators see them.']);
});
